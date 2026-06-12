<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PdfA1bTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/pdfa/a1b.pdf';
    private const string FONTS_DIR = __DIR__ . '/assets/fonts';

    public function testMatchesFixtureBytes(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            self::buildDocument()->output(),
            'Output diverges from fixture. If intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public static function buildDocument(): Document
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()
            ->title('PDF/A-1b sample')
            ->creator('phppdf')
            ->creationDate(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))
            ->documentId('0123456789abcdef0123456789abcdef');
        $doc->registerFontFamily('FS', regular: self::FONTS_DIR . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14);
        $page->text(50, 60, 'PDF 1.4 archival sample (PDF/A-1b).');
        $page->setFont(Font::custom('FS'), 11);
        $page->table(
            columns: [
                Column::of('item', 'Item')->fill(),
                Column::of('qty', 'Qty')->width(60.0),
            ],
            rows: [
                ['item' => 'Widget', 'qty' => '3'],
                ['item' => 'Gadget', 'qty' => '7'],
            ],
            x: 50.0, y: 100.0, width: 200.0,
        );
        $doc->enablePdfA(PdfALevel::A1B);
        return $doc;
    }
}
