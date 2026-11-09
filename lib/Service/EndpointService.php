<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Service;

use OCA\WebhookTable\AppInfo\Application;
use OCA\WebhookTable\Exception\WebhookException;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Verwaltet die Webhook-Endpunkte.
 *
 * Alle Endpunkte liegen app-weit in der App-Konfiguration und sind damit
 * für alle Administratoren gleich (nicht pro Benutzer).
 * Statistiken (Anzahl, letzter Eingang, letzter Fehler) liegen in eigenen
 * Schlüsseln, damit parallele Webhook-Aufrufe die Einstellungen nie überschreiben.
 */
class EndpointService {
	private const KEY_ENDPOINTS = 'endpoints';
	private const KEY_STATS_PREFIX = 'stats_';

	public const FORMATS = ['xlsx', 'csv'];

	public function __construct(
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private ISecureRandom $secureRandom,
	) {
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function load(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_ENDPOINTS, '{}');
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $endpoints
	 */
	private function store(array $endpoints): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::KEY_ENDPOINTS,
			json_encode($endpoints, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
			false,
			true, // enthält die geheimen Schlüssel -> verschlüsselt speichern
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findAll(): array {
		$result = [];
		foreach ($this->load() as $endpoint) {
			$result[] = $this->withStats($endpoint);
		}
		usort($result, static fn ($a, $b) => strcmp((string)$a['createdAt'], (string)$b['createdAt']));
		return $result;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(string $id): ?array {
		$endpoints = $this->load();
		return isset($endpoints[$id]) ? $this->withStats($endpoints[$id]) : null;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function create(array $input, string $createdBy): array {
		$id = $this->secureRandom->generate(16, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$endpoint = $this->validate($input, [
			'id' => $id,
			'token' => $this->generateToken(),
			'createdBy' => $createdBy,
			'createdAt' => gmdate('c'),
			'owner' => $createdBy,
		]);

		$endpoints = $this->load();
		$endpoints[$id] = $endpoint;
		$this->store($endpoints);
		return $this->withStats($endpoint);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update(string $id, array $input): array {
		$endpoints = $this->load();
		if (!isset($endpoints[$id])) {
			throw new WebhookException('Webhook nicht gefunden.');
		}
		$endpoints[$id] = $this->validate($input, $endpoints[$id]);
		$this->store($endpoints);
		return $this->withStats($endpoints[$id]);
	}

	public function delete(string $id): void {
		$endpoints = $this->load();
		unset($endpoints[$id]);
		$this->store($endpoints);
		$this->appConfig->deleteKey(Application::APP_ID, self::KEY_STATS_PREFIX . $id);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function regenerateToken(string $id): array {
		$endpoints = $this->load();
		if (!isset($endpoints[$id])) {
			throw new WebhookException('Webhook nicht gefunden.');
		}
		$endpoints[$id]['token'] = $this->generateToken();
		$this->store($endpoints);
		return $this->withStats($endpoints[$id]);
	}

	public function recordSuccess(string $id): void {
		$stats = $this->getStats($id);
		$stats['count'] = (int)($stats['count'] ?? 0) + 1;
		$stats['lastReceived'] = gmdate('c');
		$stats['lastError'] = null;
		$this->setStats($id, $stats);
	}

	public function recordError(string $id, string $message): void {
		$stats = $this->getStats($id);
		$stats['lastError'] = $message;
		$stats['lastErrorAt'] = gmdate('c');
		$this->setStats($id, $stats);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getStats(string $id): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_STATS_PREFIX . $id, '{}', true);
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string, mixed> $stats
	 */
	private function setStats(string $id, array $stats): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::KEY_STATS_PREFIX . $id,
			json_encode($stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
			true,
		);
	}

	/**
	 * @param array<string, mixed> $endpoint
	 * @return array<string, mixed>
	 */
	private function withStats(array $endpoint): array {
		$stats = $this->getStats((string)$endpoint['id']);
		$endpoint['count'] = (int)($stats['count'] ?? 0);
		$endpoint['lastReceived'] = $stats['lastReceived'] ?? null;
		$endpoint['lastError'] = $stats['lastError'] ?? null;
		$endpoint['lastErrorAt'] = $stats['lastErrorAt'] ?? null;
		return $endpoint;
	}

	private function generateToken(): string {
		return $this->secureRandom->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
	}

	/**
	 * Prüft die Eingaben aus der Oberfläche und übernimmt sie in den Endpunkt.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $base
	 * @return array<string, mixed>
	 */
	private function validate(array $input, array $base): array {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 100) {
			throw new WebhookException('Bitte einen Namen mit höchstens 100 Zeichen angeben.');
		}

		$owner = trim((string)($input['owner'] ?? $base['owner'] ?? ''));
		if ($owner === '' || !$this->userManager->userExists($owner)) {
			throw new WebhookException('Der Speicherort-Benutzer „' . $owner . '“ existiert nicht.');
		}

		$format = (string)($input['format'] ?? 'xlsx');
		if (!in_array($format, self::FORMATS, true)) {
			throw new WebhookException('Unbekanntes Dateiformat.');
		}

		$folder = self::normalizeFolder((string)($input['folder'] ?? '/'));

		$filename = trim((string)($input['filename'] ?? ''));
		if ($filename === '') {
			$filename = 'Einsendungen';
		}
		if (str_contains($filename, '/') || str_contains($filename, '\\') || $filename === '.' || $filename === '..') {
			throw new WebhookException('Der Dateiname darf keine Schrägstriche enthalten.');
		}
		$ext = '.' . $format;
		if (!str_ends_with(strtolower($filename), $ext)) {
			$filename = preg_replace('/\.(xlsx|csv)$/i', '', $filename) . $ext;
		}

		$mapping = (string)($input['mapping'] ?? '');
		if (mb_strlen($mapping) > 20000) {
			throw new WebhookException('Die Feldzuordnung ist zu lang.');
		}
		// Syntax prüfen, Fehler werden als Exception gemeldet
		FieldMapper::parseMapping($mapping);

		return array_merge($base, [
			'name' => $name,
			'enabled' => (bool)($input['enabled'] ?? true),
			'owner' => $owner,
			'folder' => $folder,
			'filename' => $filename,
			'format' => $format,
			'timestamp' => (bool)($input['timestamp'] ?? true),
			'mapping' => $mapping,
			'onlyMapped' => (bool)($input['onlyMapped'] ?? false),
		]);
	}

	public static function normalizeFolder(string $folder): string {
		$parts = [];
		foreach (explode('/', str_replace('\\', '/', $folder)) as $part) {
			$part = trim($part);
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				throw new WebhookException('Der Ordnerpfad darf kein „..“ enthalten.');
			}
			$parts[] = $part;
		}
		return '/' . implode('/', $parts);
	}
}
