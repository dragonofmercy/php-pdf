<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use PHPUnit\Framework\TestCase;

final class PdfUaMetadataStreamTest extends TestCase
{
    private const string FONTS_DIR = __DIR__ . '/../../Golden/assets/fonts';

    public function testUaDocumentForcesXmpMetadataStream(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $doc = new Document();
        $doc->registerFontFamily('FS', regular: self::FONTS_DIR . '/FreeSans.ttf');
        $doc->enablePdfUA('en-US');
        $doc->metadata()->title('Accessible report');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12);
        $page->cell(w: 60, h: 8, text: 'Hello');
        $bytes = $doc->output();

        self::assertStringContainsString('/Metadata', $bytes);
        self::assertStringContainsString('/Type /Metadata', $bytes);
        self::assertStringContainsString('/Subtype /XML', $bytes);
    }

    public function testPlainDocumentHasNoMetadataStream(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(w: 60, h: 8, text: 'Hello');
        $bytes = $doc->output();

        self::assertStringNotContainsString('/Metadata', $bytes);
    }
}
