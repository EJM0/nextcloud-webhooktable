<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Service;

use OCA\WebhookTable\Exception\WebhookException;

/**
 * Hängt Zeilen an eine CSV-Datei an.
 *
 * Neue Dateien werden mit Semikolon als Trennzeichen und UTF-8-BOM angelegt,
 * damit ein deutsches Excel Umlaute und Spalten direkt richtig anzeigt.
 * Bei vorhandenen Dateien wird das Trennzeichen aus der Kopfzeile erkannt.
 */
class CsvWriter {
	private const BOM = "\xEF\xBB\xBF";

	/**
	 * @param array<string, string> $row
	 */
	public function append(?string $existing, array $row): string {
		if ($existing === null || trim(str_replace(self::BOM, '', $existing)) === '') {
			return self::BOM
				. $this->line(array_keys($row), ';')
				. $this->line(array_values($row), ';');
		}

		$hasBom = str_starts_with($existing, self::BOM);
		$body = $hasBom ? substr($existing, 3) : $existing;
		$delimiter = $this->detectDelimiter($body);

		// Kopfzeile lesen (kann Zeilenumbrüche in Anführungszeichen enthalten)
		$stream = fopen('php://memory', 'r+');
		if ($stream === false) {
			throw new WebhookException('CSV-Datei konnte nicht gelesen werden.');
		}
		fwrite($stream, $body);
		rewind($stream);
		$header = fgetcsv($stream, null, $delimiter, '"', '');
		$headerEnd = ftell($stream);
		fclose($stream);
		if (!is_array($header)) {
			$header = [];
		}

		$index = [];
		foreach ($header as $i => $name) {
			$key = mb_strtolower(trim((string)$name));
			if ($key !== '' && !isset($index[$key])) {
				$index[$key] = $i;
			}
		}

		$newHeader = array_map(static fn ($v) => (string)$v, $header);
		$changed = false;
		foreach (array_keys($row) as $name) {
			$key = mb_strtolower(trim($name));
			if (!isset($index[$key])) {
				$index[$key] = count($newHeader);
				$newHeader[] = $name;
				$changed = true;
			}
		}

		$values = array_fill(0, count($newHeader), '');
		foreach ($row as $name => $value) {
			$values[$index[mb_strtolower(trim($name))]] = $value;
		}

		$lineEnding = str_contains($body, "\r\n") ? "\r\n" : "\n";
		if ($changed) {
			// Nur die Kopfzeile austauschen, der Rest der Datei bleibt unverändert
			$rest = substr($body, (int)$headerEnd);
			$body = $this->line($newHeader, $delimiter, $lineEnding) . $rest;
		}
		if ($body !== '' && !str_ends_with($body, "\n")) {
			$body .= $lineEnding;
		}
		$body .= $this->line($values, $delimiter, $lineEnding);

		return ($hasBom ? self::BOM : '') . $body;
	}

	private function detectDelimiter(string $body): string {
		$firstLine = strtok($body, "\n") ?: '';
		$counts = [
			';' => substr_count($firstLine, ';'),
			',' => substr_count($firstLine, ','),
			"\t" => substr_count($firstLine, "\t"),
		];
		arsort($counts);
		$best = array_key_first($counts);
		return $counts[$best] > 0 ? $best : ';';
	}

	/**
	 * @param list<string> $values
	 */
	private function line(array $values, string $delimiter, string $eol = "\r\n"): string {
		$out = [];
		foreach ($values as $value) {
			$value = $this->neutralizeFormula((string)$value);
			if ($value === '' || strpbrk($value, $delimiter . "\"\r\n") === false) {
				$out[] = $value;
			} else {
				$out[] = '"' . str_replace('"', '""', $value) . '"';
			}
		}
		return implode($delimiter, $out) . $eol;
	}

	/**
	 * Verhindert, dass Excel Formulareingaben als Formel ausführt (CSV-Injection).
	 * „+49 …“ oder „-5“ bleiben unverändert.
	 */
	private function neutralizeFormula(string $value): string {
		if ($value === '') {
			return $value;
		}
		$first = $value[0];
		if ($first === '=' || $first === '@' || $first === "\t" || $first === "\r") {
			return "'" . $value;
		}
		if (($first === '+' || $first === '-') && !preg_match('/^[+-][\d\s().\/]*$/', $value)) {
			return "'" . $value;
		}
		return $value;
	}
}
