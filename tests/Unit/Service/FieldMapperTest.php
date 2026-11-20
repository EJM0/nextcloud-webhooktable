<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Tests\Unit\Service;

use OCA\WebhookTable\Service\FieldMapper;
use PHPUnit\Framework\TestCase;

class FieldMapperTest extends TestCase {
	private const MAPPING = "email-1 = E-Mail\ntext-1 = Artikelname\nnumber-1 = Anzahl";

	public function testCopiedGroupFieldsBecomeRows(): void {
		$rows = FieldMapper::mapRows([
			'email-1' => 'a@b.de',
			'text-1' => 'Trikot',
			'number-1' => '2',
			'text-1-2' => 'Hose',
			'number-1-2' => '1',
		], self::MAPPING, false);

		$this->assertSame([
			['E-Mail' => 'a@b.de', 'Artikelname' => 'Trikot', 'Anzahl' => '2'],
			['Artikelname' => 'Hose', 'Anzahl' => '1'],
		], $rows);
	}

	public function testNestedListBecomesRows(): void {
		$rows = FieldMapper::mapRows([
			'email-1' => 'a@b.de',
			'artikel' => [['text-1' => 'A'], ['text-1' => 'B']],
		], self::MAPPING, false);

		$this->assertSame([
			['E-Mail' => 'a@b.de', 'Artikelname' => 'A'],
			['Artikelname' => 'B'],
		], $rows);
	}

	public function testSplitDisabledKeepsOneRow(): void {
		$rows = FieldMapper::mapRows(['text-1' => 'A', 'text-1-2' => 'B'], self::MAPPING, false, false);
		$this->assertCount(1, $rows);
		$this->assertSame('B', $rows[0]['text-1-2']);
	}
}
