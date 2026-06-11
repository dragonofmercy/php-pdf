<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PdfA\AFRelationship;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class AttachFileTest extends TestCase
{
    private const string FONTS_DIR = __DIR__ . '/../../Golden/assets/fonts';

    public function testAttachFileIsFluent(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->attachFile('<x/>', 'a.xml', mime: 'text/xml'));
    }

    public function testA2RejectsAttachment(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily('FS', regular: self::FONTS_DIR . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12);
        $page->text(50, 50, 'x');
        $doc->attachFile('<x/>', 'a.xml', mime: 'text/xml');
        $doc->enablePdfA(PdfALevel::A2B);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('forbids embedded files');
        $doc->output();
    }

    public function testA3DoesNotThrow(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily('FS', regular: self::FONTS_DIR . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12);
        $page->text(50, 50, 'x');
        $doc->attachFile('<invoice/>', 'factur-x.xml', mime: 'text/xml');
        $doc->enablePdfA(PdfALevel::A3B);

        // Should not throw (full /AF emission is verified in a later task / golden test).
        $bytes = $doc->output();
        self::assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $bytes);
    }

    public function testA3EmitsEmbeddedFileStructures(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $doc->registerFontFamily('FS', regular: self::FONTS_DIR . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::custom('FS'), 12);
        $page->text(50, 50, 'x');
        $doc->attachFile('<invoice/>', 'factur-x.xml', \DragonOfMercy\PhpPdf\PdfA\AFRelationship::Data, 'text/xml', 'Invoice');
        $doc->enablePdfA(\DragonOfMercy\PhpPdf\PdfA\PdfALevel::A3B);

        $bytes = $doc->output();
        self::assertStringContainsString('/AF', $bytes);
        self::assertStringContainsString('/EmbeddedFiles', $bytes);
        self::assertStringContainsString('/Type /Filespec', $bytes);
        self::assertStringContainsString('/Type /EmbeddedFile', $bytes);
        self::assertStringContainsString('/OutputIntents', $bytes);
    }
}
