<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Service;

use OCA\WebhookTable\Exception\WebhookException;

/**
 * Macht aus einer beliebigen (auch verschachtelten) Webhook-Nutzlast
 * eine flache Liste "Spaltenname => Wert".
 */
class FieldMapper {
	private const MAX_FIELDS = 300;
	private const MAX_VALUE_LENGTH = 32000; // Grenze einer Excel-Zelle: 32.767 Zeichen

	/**
	 * Zuordnung parsen. Eine Zeile pro Feld:
	 *   name-1 = Name
	 *   email-1 => E-Mail
	 *   honeypot =          (leerer Spaltenname: Feld wird ignoriert)
	 *   # Kommentar
	 *
	 * @return array<string, string> Feldschlüssel => Spaltenname ('' = ignorieren)
	 */
	public static function parseMapping(string $mapping): array {
		$result = [];
		$lines = preg_split('/\r\n|\r|\n/', $mapping) ?: [];
		foreach ($lines as $i => $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			if (!preg_match('/^(.+?)\s*(=>|=)\s*(.*)$/u', $line, $m)) {
				throw new WebhookException('Feldzuordnung, Zeile ' . ($i + 1) . ': erwartet wird „feld = Spaltenname“.');
			}
			$key = trim($m[1]);
			if ($key === '') {
				throw new WebhookException('Feldzuordnung, Zeile ' . ($i + 1) . ': Feldname fehlt.');
			}
			$result[$key] = trim($m[3]);
		}
		return $result;
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @return array<string, string> Spaltenname => Wert (Reihenfolge wie in der Nutzlast)
	 */
	public static function map(array $payload, string $mapping, bool $onlyMapped): array {
		$flat = [];
		self::flatten($payload, '', $flat);
		return self::mapFlat($flat, self::parseMapping($mapping), $onlyMapped);
	}

	/**
	 * Wie map(), aber wiederholte Gruppen (z. B. mehrere Artikel einer Bestellung) werden zu eigenen Zeilen:
	 * Die erste Zeile enthält alle übrigen Felder (Name, E-Mail …) und den ersten Eintrag der Gruppe,
	 * jede weitere Zeile nur die Felder des nächsten Eintrags – alles andere bleibt dort leer.
	 *
	 * Erkannt werden
	 *  - Kopien mit angehängter Nummer, wie Formulare sie für wiederholte Gruppen senden:
	 *    text-1, text-1-2, text-1-3 … (das Feld ohne Nummer ist der erste Eintrag)
	 *  - Listen von Objekten in JSON: {"artikel": [{"name": …}, {"name": …}]}
	 *  - in $groupFields aufgeführte Felder (technischer Name oder Spaltenname, durch Zeilen oder Kommas getrennt),
	 *    damit auch ein einzelner Eintrag als Gruppe gilt
	 *
	 * @param array<array-key, mixed> $payload
	 * @return list<array<string, string>> Zeilen, jede Spaltenname => Wert
	 */
	public static function mapRows(array $payload, string $mapping, bool $onlyMapped, bool $split = true, string $groupFields = ''): array {
		$map = self::parseMapping($mapping);
		$flat = [];
		self::flatten($payload, '', $flat);
		if (!$split) {
			return [self::mapFlat($flat, $map, $onlyMapped)];
		}

		$configured = [];
		foreach (preg_split('/[\r\n,;]+/', $groupFields) ?: [] as $name) {
			$name = mb_strtolower(trim($name));
			if ($name !== '') {
				$configured[$name] = true;
			}
		}
		$isConfigured = static function (string $key) use ($configured, $map): bool {
			return isset($configured[mb_strtolower($key)])
				|| (isset($map[$key]) && $map[$key] !== '' && isset($configured[mb_strtolower($map[$key])]));
		};

		// Je Feld: zu welchem Eintrag der Gruppe es gehört (null = kein Gruppenfeld) und unter welchem Namen
		$entries = [];
		$copiedBases = [];
		foreach ($flat as $key => $value) {
			$key = (string)$key;
			if (preg_match('/^(.+?)\.(\d+)\.(.+)$/u', $key, $m)) {
				$entries[] = [$m[3], (int)$m[2], $value];
				continue;
			}
			if (preg_match('/^(.+-\d+)-(\d+)$/u', $key, $m) && (array_key_exists($m[1], $flat) || $isConfigured($m[1]))) {
				$copiedBases[$m[1]] = true;
				$entries[] = [$m[1], max(0, (int)$m[2] - 1), $value];
				continue;
			}
			$entries[] = [$key, null, $value];
		}
		foreach ($entries as $i => [$key, $item, $value]) {
			if ($item === null && (isset($copiedBases[$key]) || $isConfigured($key))) {
				$entries[$i][1] = 0;
			}
		}

		$items = [];
		foreach ($entries as [, $item]) {
			if ($item !== null) {
				$items[$item] = true;
			}
		}
		if ($items === []) {
			return [self::mapFlat($flat, $map, $onlyMapped)];
		}
		ksort($items);
		$order = array_keys($items);
		$first = $order[0];

		$rows = [];
		foreach ($order as $item) {
			$part = [];
			foreach ($entries as [$key, $entryItem, $value]) {
				if ($entryItem === $item || ($item === $first && $entryItem === null)) {
					if (!array_key_exists($key, $part) || $part[$key] === '') {
						$part[$key] = $value;
					}
				}
			}
			$row = self::mapFlat($part, $map, $onlyMapped);
			if ($item !== $first && implode('', $row) === '') {
				continue; // leerer, angefangener Eintrag
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @param array<string, string> $flat
	 * @param array<string, string> $map
	 * @return array<string, string>
	 */
	private static function mapFlat(array $flat, array $map, bool $onlyMapped): array {
		$row = [];
		foreach ($flat as $key => $value) {
			if (array_key_exists($key, $map)) {
				$column = $map[$key];
				if ($column === '') {
					continue; // bewusst ignoriert
				}
			} elseif ($onlyMapped) {
				continue;
			} else {
				$column = $key;
			}
			$column = self::cleanColumnName($column);
			if ($column === '') {
				continue;
			}
			// Mehrere Felder auf dieselbe Spalte: Werte zusammenführen
			if (isset($row[$column]) && $row[$column] !== '' && $value !== '') {
				$row[$column] .= ', ' . $value;
			} elseif (!isset($row[$column]) || $row[$column] === '') {
				$row[$column] = $value;
			}
			if (count($row) >= self::MAX_FIELDS) {
				break;
			}
		}
		return $row;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param array<string, string> $out
	 */
	private static function flatten(array $data, string $prefix, array &$out, int $depth = 0): void {
		foreach ($data as $key => $value) {
			$name = $prefix === '' ? (string)$key : $prefix . '.' . $key;
			if (is_array($value)) {
				if ($depth < 5 && !array_is_list($value)) {
					self::flatten($value, $name, $out, $depth + 1);
					continue;
				}
				if (array_is_list($value) && self::allScalar($value)) {
					$out[$name] = self::limit(implode(', ', array_map([self::class, 'scalarToString'], $value)));
					continue;
				}
				if ($depth < 5) {
					self::flatten($value, $name, $out, $depth + 1);
					continue;
				}
				$out[$name] = self::limit((string)json_encode($value, JSON_UNESCAPED_UNICODE));
				continue;
			}
			$out[$name] = self::limit(self::scalarToString($value));
		}
	}

	/**
	 * @param list<mixed> $values
	 */
	private static function allScalar(array $values): bool {
		foreach ($values as $v) {
			if (!is_scalar($v) && $v !== null) {
				return false;
			}
		}
		return true;
	}

	private static function scalarToString(mixed $value): string {
		if ($value === null) {
			return '';
		}
		if (is_bool($value)) {
			return $value ? 'ja' : 'nein';
		}
		if (is_scalar($value)) {
			return (string)$value;
		}
		return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
	}

	private static function limit(string $value): string {
		// Steuerzeichen (außer Tab/Zeilenumbruch) entfernen, sie sind in XML ungültig
		$value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
		if (!mb_check_encoding($value, 'UTF-8')) {
			$value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
		}
		return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
	}

	private static function cleanColumnName(string $name): string {
		$name = trim(self::limit($name));
		return mb_substr($name, 0, 255);
	}
}
