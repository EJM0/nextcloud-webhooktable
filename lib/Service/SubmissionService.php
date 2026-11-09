<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Service;

use OCA\WebhookTable\Exception\WebhookException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Schreibt eine eingegangene Einsendung in die Zieldatei eines Endpunkts.
 */
class SubmissionService {
	private const MAX_FILE_SIZE = 50 * 1024 * 1024;
	private const LOCK_WAIT_SECONDS = 15;

	public function __construct(
		private IRootFolder $rootFolder,
		private ILockingProvider $lockingProvider,
		private ITempManager $tempManager,
		private IConfig $config,
		private LoggerInterface $logger,
		private XlsxWriter $xlsxWriter,
		private CsvWriter $csvWriter,
	) {
	}

	/**
	 * @param array<string, mixed> $endpoint
	 * @param array<array-key, mixed> $payload
	 * @return int Anzahl geschriebener Spalten
	 */
	public function store(array $endpoint, array $payload): int {
		$row = FieldMapper::map($payload, (string)$endpoint['mapping'], (bool)$endpoint['onlyMapped']);
		if ($row === []) {
			throw new WebhookException('Die Anfrage enthielt keine verwertbaren Felder.');
		}
		if ($endpoint['timestamp']) {
			unset($row['Eingang']);
			$row = ['Eingang' => $this->now((string)$endpoint['owner'])] + $row;
		}

		$lockKey = 'webhooktable/' . $endpoint['id'];
		$this->acquireLock($lockKey);
		try {
			$folder = $this->getFolder((string)$endpoint['owner'], (string)$endpoint['folder']);
			$filename = (string)$endpoint['filename'];

			$file = null;
			$existing = null;
			try {
				$node = $folder->get($filename);
				if (!$node instanceof File) {
					throw new WebhookException('„' . $filename . '“ ist ein Ordner, keine Datei.');
				}
				if ($node->getSize() > self::MAX_FILE_SIZE) {
					throw new WebhookException('Die Zieldatei ist größer als 50 MB. Bitte eine neue Datei wählen.');
				}
				$file = $node;
				$existing = $file->getContent();
			} catch (NotFoundException) {
				// wird neu angelegt
			}

			if ($endpoint['format'] === 'csv') {
				$content = $this->csvWriter->append($existing, $row);
			} else {
				$tmp = $this->tempManager->getTemporaryFile('.xlsx');
				if ($tmp === false) {
					throw new WebhookException('Temporäre Datei konnte nicht angelegt werden.');
				}
				try {
					$content = $this->xlsxWriter->append($existing, $row, $tmp);
				} finally {
					@unlink($tmp);
				}
			}

			if ($file === null) {
				$folder->newFile($filename, $content);
			} else {
				$file->putContent($content);
			}
		} catch (LockedException) {
			throw new WebhookException('Die Zieldatei ist gerade gesperrt (z. B. geöffnet oder wird synchronisiert). Bitte später erneut senden.');
		} catch (NotPermittedException $e) {
			$this->logger->warning('Webhook-Tabelle: Schreiben nicht erlaubt', ['exception' => $e]);
			throw new WebhookException('Keine Schreibrechte für die Zieldatei oder den Ordner.');
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}

		return count($row);
	}

	private function acquireLock(string $key): void {
		$deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
		while (true) {
			try {
				$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'webhooktable');
				return;
			} catch (LockedException $e) {
				if (microtime(true) > $deadline) {
					throw new WebhookException('Zu viele gleichzeitige Einsendungen, bitte erneut versuchen.');
				}
				usleep(200000);
			}
		}
	}

	private function getFolder(string $owner, string $path): Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($owner);
		} catch (\Throwable $e) {
			throw new WebhookException('Speicherort-Benutzer „' . $owner . '“ ist nicht verfügbar.');
		}

		$folder = $userFolder;
		foreach (array_filter(explode('/', $path), static fn ($p) => $p !== '') as $part) {
			try {
				$node = $folder->get($part);
			} catch (NotFoundException) {
				$node = $folder->newFolder($part);
			}
			if (!$node instanceof Folder) {
				throw new WebhookException('„' . $part . '“ im Zielpfad ist kein Ordner.');
			}
			$folder = $node;
		}
		return $folder;
	}

	private function now(string $owner): string {
		$tz = $this->config->getUserValue($owner, 'core', 'timezone', '')
			?: $this->config->getSystemValueString('default_timezone', 'Europe/Berlin');
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		return (new \DateTimeImmutable('now', $zone))->format('d.m.Y H:i:s');
	}
}
