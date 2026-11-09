<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OCA\WebhookTable\Exception\WebhookException;
use ZipArchive;

/**
 * Hängt Zeilen an eine bestehende .xlsx-Datei an oder legt eine neue an.
 *
 * Es wird nur das XML des ersten Tabellenblatts verändert. Formatierungen,
 * weitere Blätter, Spaltenbreiten und eigene Spalten (z. B. „Bezahlt“) bleiben
 * erhalten. Werte werden als Text (Inline-Strings) geschrieben, damit Excel
 * sie nie als Formel auswertet und führende Nullen (Telefonnummern) bleiben.
 */
class XlsxWriter {
	private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/**
	 * @param string|null $existing Inhalt der vorhandenen Datei oder null, wenn sie neu angelegt wird
	 * @param array<string, string> $row Spaltenname => Wert
	 * @param string $tmpFile Pfad einer leeren temporären Datei
	 * @return string Neuer Dateiinhalt
	 */
	public function append(?string $existing, array $row, string $tmpFile): string {
		if ($existing === null || $existing === '') {
			return $this->create($row, $tmpFile);
		}

		file_put_contents($tmpFile, $existing);
		$zip = new ZipArchive();
		if ($zip->open($tmpFile) !== true) {
			throw new WebhookException('Die Zieldatei ist keine gültige Excel-Datei (.xlsx).');
		}
		try {
			$sheetPath = $this->findFirstSheetPath($zip);
			$sheetXml = $zip->getFromName($sheetPath);
			if ($sheetXml === false) {
				throw new WebhookException('Das erste Tabellenblatt konnte nicht gelesen werden.');
			}
			$sharedStrings = $this->readSharedStrings($zip);
			$newXml = $this->appendToSheet($sheetXml, $sharedStrings, $row);
			$zip->addFromString($sheetPath, $newXml);
		} finally {
			$zip->close();
		}

		$result = file_get_contents($tmpFile);
		if ($result === false) {
			throw new WebhookException('Temporäre Datei konnte nicht gelesen werden.');
		}
		return $result;
	}

	private function findFirstSheetPath(ZipArchive $zip): string {
		$workbookXml = $zip->getFromName('xl/workbook.xml');
		$relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
		if ($workbookXml === false || $relsXml === false) {
			throw new WebhookException('Die Zieldatei ist keine gültige Excel-Datei (.xlsx).');
		}

		$wb = $this->loadXml($workbookXml);
		$xp = new DOMXPath($wb);
		$xp->registerNamespace('m', self::NS_MAIN);
		$sheet = $xp->query('//m:sheets/m:sheet')->item(0);
		if (!$sheet instanceof DOMElement) {
			throw new WebhookException('Die Excel-Datei enthält kein Tabellenblatt.');
		}
		$rid = $sheet->getAttributeNS(self::NS_REL, 'id');

		$rels = $this->loadXml($relsXml);
		$xr = new DOMXPath($rels);
		$xr->registerNamespace('r', self::NS_PKG_REL);
		foreach ($xr->query('//r:Relationship') as $rel) {
			if ($rel instanceof DOMElement && $rel->getAttribute('Id') === $rid) {
				$target = $rel->getAttribute('Target');
				$path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
				return $this->normalizeZipPath($path);
			}
		}
		throw new WebhookException('Das erste Tabellenblatt wurde nicht gefunden.');
	}

	private function normalizeZipPath(string $path): string {
		$parts = [];
		foreach (explode('/', $path) as $p) {
			if ($p === '' || $p === '.') {
				continue;
			}
			if ($p === '..') {
				array_pop($parts);
				continue;
			}
			$parts[] = $p;
		}
		return implode('/', $parts);
	}

	/**
	 * @return list<string>
	 */
	private function readSharedStrings(ZipArchive $zip): array {
		$xml = $zip->getFromName('xl/sharedStrings.xml');
		if ($xml === false) {
			return [];
		}
		$doc = $this->loadXml($xml);
		$xp = new DOMXPath($doc);
		$xp->registerNamespace('m', self::NS_MAIN);
		$result = [];
		foreach ($xp->query('/m:sst/m:si') as $si) {
			$text = '';
			foreach ($xp->query('.//m:t', $si) as $t) {
				// Phonetische Hilfstexte (rPh) nicht mitnehmen
				if ($t->parentNode !== null && $t->parentNode->localName === 'rPh') {
					continue;
				}
				$text .= $t->textContent;
			}
			$result[] = $text;
		}
		return $result;
	}

	/**
	 * @param list<string> $sharedStrings
	 * @param array<string, string> $row
	 */
	private function appendToSheet(string $sheetXml, array $sharedStrings, array $row): string {
		$doc = $this->loadXml($sheetXml);
		$xp = new DOMXPath($doc);
		$xp->registerNamespace('m', self::NS_MAIN);

		$sheetData = $xp->query('/m:worksheet/m:sheetData')->item(0);
		if (!$sheetData instanceof DOMElement) {
			throw new WebhookException('Das Tabellenblatt hat ein unbekanntes Format.');
		}

		// Zeilen einlesen (r-Attribute können laut Standard fehlen)
		/** @var array<int, DOMElement> $rows */
		$rows = [];
		$lastRowNum = 0;
		$lastDataRow = 0;
		$maxCol = 0;
		foreach ($sheetData->childNodes as $node) {
			if (!$node instanceof DOMElement || $node->localName !== 'row') {
				continue;
			}
			$r = $node->hasAttribute('r') ? (int)$node->getAttribute('r') : $lastRowNum + 1;
			$lastRowNum = $r;
			$rows[$r] = $node;
			$col = 0;
			foreach ($this->cells($node) as $cell) {
				$col = $cell->hasAttribute('r') ? self::columnIndex($cell->getAttribute('r')) : $col + 1;
				if ($this->cellHasValue($cell, $xp)) {
					$lastDataRow = max($lastDataRow, $r);
					$maxCol = max($maxCol, $col);
				}
			}
		}

		// Kopfzeile lesen: Spaltenname (normalisiert) => Spaltenindex
		$header = [];
		$headerMax = 0;
		if (isset($rows[1])) {
			$col = 0;
			foreach ($this->cells($rows[1]) as $cell) {
				$col = $cell->hasAttribute('r') ? self::columnIndex($cell->getAttribute('r')) : $col + 1;
				$text = trim($this->cellText($cell, $xp, $sharedStrings));
				if ($text !== '') {
					$key = mb_strtolower($text);
					if (!isset($header[$key])) {
						$header[$key] = $col;
					}
					$headerMax = max($headerMax, $col);
				}
			}
		} else {
			$headerRow = $doc->createElementNS(self::NS_MAIN, 'row');
			$headerRow->setAttribute('r', '1');
			$sheetData->insertBefore($headerRow, $sheetData->firstChild);
			$rows = [1 => $headerRow] + $rows;
			ksort($rows);
		}

		// Neue Spalten rechts an die Kopfzeile anhängen
		$nextCol = max($headerMax, $maxCol) + 1;
		$targetCols = [];
		$headerStyle = $this->firstStyle($rows[1]);
		foreach (array_keys($row) as $name) {
			$key = mb_strtolower(trim($name));
			if (!isset($header[$key])) {
				$header[$key] = $nextCol;
				$this->setCell($doc, $rows[1], 1, $nextCol, $name, $headerStyle);
				$nextCol++;
			}
			$targetCols[$name] = $header[$key];
		}

		// Neue Datenzeile direkt unter der letzten gefüllten Zeile
		$newRowNum = max($lastDataRow, 1) + 1;
		if (isset($rows[$newRowNum])) {
			$rowEl = $rows[$newRowNum]; // leere, evtl. formatierte Zeile weiterverwenden
		} else {
			$rowEl = $doc->createElementNS(self::NS_MAIN, 'row');
			$rowEl->setAttribute('r', (string)$newRowNum);
			$before = null;
			foreach ($rows as $num => $el) {
				if ($num > $newRowNum) {
					$before = $el;
					break;
				}
			}
			$sheetData->insertBefore($rowEl, $before);
		}
		foreach ($row as $name => $value) {
			if ($value === '') {
				continue;
			}
			$this->setCell($doc, $rowEl, $newRowNum, $targetCols[$name], $value, null);
		}
		$rowEl->removeAttribute('spans');
		$rows[1]->removeAttribute('spans');

		// Bereichsangabe aktualisieren
		$lastCol = max($nextCol - 1, $maxCol, 1);
		$lastRow = max($newRowNum, $lastRowNum);
		$dim = $xp->query('/m:worksheet/m:dimension')->item(0);
		if ($dim instanceof DOMElement) {
			$dim->setAttribute('ref', 'A1:' . self::columnLetter($lastCol) . $lastRow);
		}

		$xml = $doc->saveXML();
		if ($xml === false) {
			throw new WebhookException('Tabellenblatt konnte nicht gespeichert werden.');
		}
		return $xml;
	}

	/**
	 * @return list<DOMElement>
	 */
	private function cells(DOMElement $row): array {
		$cells = [];
		foreach ($row->childNodes as $c) {
			if ($c instanceof DOMElement && $c->localName === 'c') {
				$cells[] = $c;
			}
		}
		return $cells;
	}

	private function firstStyle(DOMElement $row): ?string {
		foreach ($this->cells($row) as $c) {
			if ($c->hasAttribute('s')) {
				return $c->getAttribute('s');
			}
		}
		return null;
	}

	private function cellHasValue(DOMElement $cell, DOMXPath $xp): bool {
		foreach ($xp->query('m:v|m:is|m:f', $cell) as $n) {
			if (trim($n->textContent) !== '') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<string> $sharedStrings
	 */
	private function cellText(DOMElement $cell, DOMXPath $xp, array $sharedStrings): string {
		$type = $cell->getAttribute('t');
		if ($type === 'inlineStr') {
			$text = '';
			foreach ($xp->query('m:is//m:t', $cell) as $t) {
				$text .= $t->textContent;
			}
			return $text;
		}
		$v = $xp->query('m:v', $cell)->item(0);
		if ($v === null) {
			return '';
		}
		if ($type === 's') {
			return $sharedStrings[(int)$v->textContent] ?? '';
		}
		return $v->textContent;
	}

	/**
	 * Setzt eine Text-Zelle an der richtigen Position in der Zeile (sortiert nach Spalte).
	 */
	private function setCell(DOMDocument $doc, DOMElement $row, int $rowNum, int $col, string $value, ?string $style): void {
		$ref = self::columnLetter($col) . $rowNum;

		$cell = $doc->createElementNS(self::NS_MAIN, 'c');
		$cell->setAttribute('r', $ref);
		if ($style !== null) {
			$cell->setAttribute('s', $style);
		}
		$cell->setAttribute('t', 'inlineStr');
		$is = $doc->createElementNS(self::NS_MAIN, 'is');
		$t = $doc->createElementNS(self::NS_MAIN, 't');
		if ($value !== trim($value) || str_contains($value, "\n")) {
			$t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
		}
		$t->appendChild($doc->createTextNode($value));
		$is->appendChild($t);
		$cell->appendChild($is);

		$current = 0;
		foreach ($this->cells($row) as $existing) {
			$current = $existing->hasAttribute('r') ? self::columnIndex($existing->getAttribute('r')) : $current + 1;
			if ($current === $col) {
				// vorhandene (leere) Zelle ersetzen, Formatierung übernehmen
				if ($style === null && $existing->hasAttribute('s')) {
					$cell->setAttribute('s', $existing->getAttribute('s'));
				}
				$row->replaceChild($cell, $existing);
				return;
			}
			if ($current > $col) {
				$row->insertBefore($cell, $existing);
				return;
			}
		}
		// Nicht-Zellen-Elemente (extLst) stehen nie in <row>, also ans Ende
		$row->appendChild($cell);
	}

	private function loadXml(string $xml): DOMDocument {
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = true;
		$prev = libxml_use_internal_errors(true);
		// LIBXML_NONET: keine externen Ressourcen; Entitäten werden nicht ersetzt
		$ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) {
			throw new WebhookException('Die Excel-Datei enthält ungültiges XML.');
		}
		return $doc;
	}

	public static function columnIndex(string $ref): int {
		if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
			return 0;
		}
		$index = 0;
		foreach (str_split(strtoupper($m[1])) as $ch) {
			$index = $index * 26 + (ord($ch) - 64);
		}
		return $index;
	}

	public static function columnLetter(int $index): string {
		$letters = '';
		while ($index > 0) {
			$mod = ($index - 1) % 26;
			$letters = chr(65 + $mod) . $letters;
			$index = intdiv($index - 1, 26);
		}
		return $letters;
	}

	/**
	 * Legt eine neue Arbeitsmappe mit hervorgehobener, fixierter Kopfzeile an.
	 *
	 * @param array<string, string> $row
	 */
	private function create(array $row, string $tmpFile): string {
		$esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$cell = static function (int $col, int $r, string $value, bool $bold) use ($esc): string {
			$space = ($value !== trim($value) || str_contains($value, "\n")) ? ' xml:space="preserve"' : '';
			return '<c r="' . self::columnLetter($col) . $r . '"' . ($bold ? ' s="1"' : '') . ' t="inlineStr"><is><t' . $space . '>' . $esc($value) . '</t></is></c>';
		};

		$headerCells = '';
		$dataCells = '';
		$cols = '';
		$i = 1;
		foreach ($row as $name => $value) {
			$headerCells .= $cell($i, 1, (string)$name, true);
			if ($value !== '') {
				$dataCells .= $cell($i, 2, $value, false);
			}
			$width = min(60, max(12, mb_strlen((string)$name) + 4, mb_strlen($value) + 2));
			$cols .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
			$i++;
		}
		$lastCol = self::columnLetter(max(1, $i - 1));

		$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
			. '<dimension ref="A1:' . $lastCol . '2"/>'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
			. '<sheetData><row r="1">' . $headerCells . '</row><row r="2">' . $dataCells . '</row></sheetData>'
			. '</worksheet>';

		$files = [
			'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
				. '<Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
				. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
				. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
				. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
				. '</Types>',
			'_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Relationships xmlns="' . self::NS_PKG_REL . '">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
				. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
				. '</Relationships>',
			'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Nextcloud Webhook-Tabelle</Application></Properties>',
			'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
				. '<sheets><sheet name="Einsendungen" sheetId="1" r:id="rId1"/></sheets>'
				. '</workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Relationships xmlns="' . self::NS_PKG_REL . '">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
				. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
				. '</Relationships>',
			'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<styleSheet xmlns="' . self::NS_MAIN . '">'
				. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
				. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
				. '<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill></fills>'
				. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
				. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
				. '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
				. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
				. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
				. '</styleSheet>',
			'xl/worksheets/sheet1.xml' => $sheet,
		];

		@unlink($tmpFile);
		$zip = new ZipArchive();
		if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new WebhookException('Excel-Datei konnte nicht erstellt werden.');
		}
		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();

		$result = file_get_contents($tmpFile);
		if ($result === false) {
			throw new WebhookException('Temporäre Datei konnte nicht gelesen werden.');
		}
		return $result;
	}
}
