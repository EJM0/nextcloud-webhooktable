<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Service;

use OCA\WebhookTable\Exception\FileLockedException;
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
		private EndpointService $endpoints,
	) {
	}

	/**
	 * Nimmt eine Einsendung an. Ist die Zieldatei gerade gesperrt (z. B. in Nextcloud Office geöffnet),
	 * wird sie zwischengespeichert und später in Eingangsreihenfolge nachgetragen.
	 *
	 * @param array<string, mixed> $endpoint
	 * @param array<array-key, mixed> $payload
	 * @return array{columns: int, queued: bool, pending: int}
	 */
	public function store(array $endpoint, array $payload): array {
		$rows = FieldMapper::mapRows(
			$payload,
			(string)$endpoint['mapping'],
			(bool)$endpoint['onlyMapped'],
			(bool)($endpoint['splitGroups'] ?? true),
			(string)($endpoint['groupFields'] ?? ''),
		);
		$rows = array_values(array_filter($rows, static fn (array $r): bool => $r !== []));
		if ($rows === []) {
			throw new WebhookException('Die Anfrage enthielt keine verwertbaren Felder.');
		}
		// Datum und Uhrzeit nur in der ersten Zeile: Folgezeilen gehören zur selben Einsendung
		if ($endpoint['timestamp']) {
			unset($rows[0]['Eingang']);
			$rows[0] = ['Eingang' => $this->now((string)$endpoint['owner'])] + $rows[0];
		}
		$columns = count(array_unique(array_merge(...array_map('array_keys', $rows))));

		$id = (string)$endpoint['id'];
		$lockKey = 'webhooktable/' . $id;
		$this->acquireLock($lockKey);
		try {
			// Wartende Einsendungen zuerst, damit die Reihenfolge erhalten bleibt
			$queue = $this->endpoints->getQueue($id);
			$queue[] = $rows;
			try {
				$this->write($endpoint, array_merge(...$queue));
			} catch (FileLockedException) {
				$this->endpoints->setQueue($id, $queue);
				$this->logger->info('Webhook-Tabelle: Zieldatei gesperrt, Einsendung wartet', ['endpoint' => $id, 'pending' => count($queue)]);
				return ['columns' => $columns, 'queued' => true, 'pending' => count($queue)];
			}
			if (count($queue) > 1) {
				$this->endpoints->setQueue($id, []);
			}
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
		return ['columns' => $columns, 'queued' => false, 'pending' => 0];
	}

	/**
	 * Versucht, wartende Einsendungen nachzutragen.
	 *
	 * @param array<string, mixed> $endpoint
	 * @return int Anzahl nachgetragener Einsendungen (0, wenn die Datei noch gesperrt ist)
	 */
	public function flushQueue(array $endpoint): int {
		$id = (string)$endpoint['id'];
		$lockKey = 'webhooktable/' . $id;
		try {
			$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'webhooktable');
		} catch (LockedException) {
			return 0; // gerade schreibt eine neue Einsendung, die nimmt die Warteschlange mit
		}
		try {
			$queue = $this->endpoints->getQueue($id);
			if ($queue === []) {
				return 0;
			}
			try {
				$this->write($endpoint, array_merge(...$queue));
			} catch (FileLockedException) {
				return 0;
			}
			$this->endpoints->setQueue($id, []);
			return count($queue);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Hängt die Zeilen an die Zieldatei an. Alles wird einmal gespeichert.
	 *
	 * @param array<string, mixed> $endpoint
	 * @param list<array<string, string>> $rows
	 * @throws FileLockedException wenn die Datei gesperrt ist
	 */
	private function write(array $endpoint, array $rows): void {
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

			$content = $existing;
			if ($endpoint['format'] === 'csv') {
				foreach ($rows as $row) {
					$content = $this->csvWriter->append($content, $row);
				}
			} else {
				$tmp = $this->tempManager->getTemporaryFile('.xlsx');
				if ($tmp === false) {
					throw new WebhookException('Temporäre Datei konnte nicht angelegt werden.');
				}
				try {
					foreach ($rows as $row) {
						$content = $this->xlsxWriter->append($content, $row, $tmp);
					}
				} finally {
					@unlink($tmp);
				}
			}

			if ($file === null) {
				$folder->newFile($filename, $content);
			} else {
				$file->putContent($content);
			}
		} catch (WebhookException $e) {
			throw $e;
		} catch (NotPermittedException $e) {
			if (self::isLock($e)) {
				throw new FileLockedException('Zieldatei gesperrt', 0, $e);
			}
			$this->logger->warning('Webhook-Tabelle: Schreiben nicht erlaubt', ['exception' => $e]);
			throw new WebhookException('Keine Schreibrechte für die Zieldatei oder den Ordner.');
		} catch (\Throwable $e) {
			if (self::isLock($e)) {
				throw new FileLockedException('Zieldatei gesperrt', 0, $e);
			}
			throw $e;
		}
	}

	/**
	 * Sperren gibt es in mehreren Formen: Nextcloud-Dateisperre (LockedException),
	 * Sperre durch Office/„Temporary files lock“ (ManuallyLockedException) – teils verpackt in andere Fehler.
	 */
	private static function isLock(\Throwable $error): bool {
		for ($e = $error, $i = 0; $e !== null && $i < 5; $e = $e->getPrevious(), $i++) {
			if ($e instanceof LockedException || is_a($e, 'OCP\\Files\\LockNotAcquiredException')) {
				return true;
			}
		}
		return false;
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
