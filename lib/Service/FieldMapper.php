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
		$map = self::parseMapping($mapping);
		$flat = [];
		self::flatten($payload, '', $flat);

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
