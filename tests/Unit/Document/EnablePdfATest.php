<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class EnablePdfATest extends TestCase
{
    private const string FONTS_DIR = __DIR__ . '/../../Golden/assets/fonts';

    public function testEnablePdfAIsFluent(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->enablePdfA(PdfALevel::A2B));
    }

    public function testThrowsWhenStandardFontUsed(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->text(50, 50, 'hello');
        $doc->enablePdfA(PdfALevel::A2B);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Helvetica');
        $doc->output();
    }

    public function testForcesMetadataPathWithCustomFont(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily('FS', regular: self::FONTS_DIR . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12);
        $page->text(50, 50, 'hello pdfa');
        $doc->enablePdfA(PdfALevel::A2B);

        $bytes = $doc->output();
        // Metadata path forced => XMP metadata stream + pdfaid present even with no user metadata.
        self::assertStringContainsString('/Metadata', $bytes);
        self::assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $bytes);
    }
}
