<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class TemplateImportTest extends TestCase
{
    public static function buildLetterheadOverlayBytes(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $source = $doc->importPdf(__DIR__ . '/assets/import/letterhead.pdf');
        $page->template($source->page(1), 0, 0);                       // full-page background
        $page->setFont(Font::helvetica(), 12);
        $page->text(72, 200, 'Body text written over the imported letterhead.');
        $page2 = $doc->addPage();
        $page2->template($source->page(1), x: 60, y: 60, width: 200);  // scaled stamp
        return $doc->output();
    }

    public static function buildRotatedImportBytes(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $source = $doc->importPdf(__DIR__ . '/assets/import/letterhead-rotated.pdf');
        $page->template($source->page(1), x: 30, y: 30, width: 400);
        return $doc->output();
    }

    public function testLetterheadOverlayMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/import/letterhead-overlay.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildLetterheadOverlayBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php');
    }

    public function testLetterheadOverlayPassesQpdfCheck(): void
    {
        $this->assertQpdfClean(self::buildLetterheadOverlayBytes());
    }

    public function testRotatedImportMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/import/rotated-source.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildRotatedImportBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php');
    }

    public function testRotatedImportPassesQpdfCheck(): void
    {
        $this->assertQpdfClean(self::buildRotatedImportBytes());
    }

    private function assertQpdfClean(string $bytes): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf_tpl_golden_');
        self::assertIsString($tmp);
        try {
            file_put_contents($tmp, $bytes);
            Qpdf::assertCheck($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
