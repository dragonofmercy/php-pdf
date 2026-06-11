<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PdfA\AFRelationship;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PdfA4fTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/pdfa/a4f.pdf';
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
            ->title('PDF/A-4f sample')
            ->creator('phppdf')
            ->creationDate(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))
            ->documentId('0123456789abcdef0123456789abcdef');
        $doc->registerFontFamily('FS', regular: self::FONTS_DIR . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14);
        $page->text(50, 60, 'PDF 2.0 hybrid carrier (PDF/A-4f).');
        $doc->attachFile(
            '<?xml version="1.0"?><invoice><total>42.00</total></invoice>',
            'factur-x.xml',
            AFRelationship::Data,
            'text/xml',
            'Factur-X invoice data',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $doc->enablePdfA(PdfALevel::A4F);
        return $doc;
    }
}
