<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Outline\Link;
use PHPUnit\Framework\TestCase;

final class UaLinkGuardIntegrationTest extends TestCase
{
    private const string FONTS_DIR = __DIR__ . '/../../Golden/assets/fonts';

    public function testUaDocumentWithTaggedCellLinkOutputsWithoutThrowing(): void
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
        $page->cell(w: 80, h: 8, text: 'Visit example.com', link: Link::url('https://example.com'), linkAlt: 'Example home page');

        $bytes = $doc->output();
        self::assertStringContainsString('/OBJR', $bytes);
    }

    public function testUaDocumentWithRawAreaLinkThrows(): void
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
        $page->cell(w: 80, h: 8, text: 'Body text');
        $page->link(x: 10, y: 10, width: 40, height: 8, link: Link::url('https://example.com'));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('cell(link:');
        $doc->output();
    }
}
