<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\PageFormat;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use PHPUnit\Framework\TestCase;

/**
 * PDF/A-1 (PDF 1.4-based) forbids transparency entirely. These tests pin the
 * fail-fast guard: a PDF/A-1 document that would emit a PNG alpha channel, an
 * SVG fill/stroke opacity below 1.0, or an SVG mask/soft-mask must throw at
 * output() time, while opaque content and non-PDF/A documents are unaffected.
 */
final class PdfA1TransparencyGuardTest extends TestCase
{
    private const string ALPHA_PNG = __DIR__ . '/../../Golden/assets/png-alpha-rgba-16x16.png';
    private const string OPAQUE_PNG = __DIR__ . '/../../Golden/assets/png-opaque-rgb-24x12.png';

    private const string OPACITY_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><rect x="2" y="2" width="16" height="16" fill="#ff0000" fill-opacity="0.5"/></svg>';

    private const string MASK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><defs><mask id="m"><rect x="0" y="0" width="20" height="20" fill="#ffffff"/><rect x="5" y="5" width="10" height="10" fill="#000000"/></mask></defs><rect x="0" y="0" width="20" height="20" fill="#0000ff" mask="url(#m)"/></svg>';

    private const string FILTER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><filter id="f"><feGaussianBlur stdDeviation="3"/></filter><rect x="20" y="20" width="60" height="60" fill="red" filter="url(#f)"/></svg>';

    private const string EMBED_FONT = __DIR__ . '/../../Golden/assets/fonts/FreeSans.ttf';

    public function testOpaqueTextPageSucceedsUnderA1B(): void
    {
        // PDF/A-1 requires embedded fonts, so register one before drawing text.
        $doc = new Document();
        $doc->registerFontFamily('FS', regular: self::EMBED_FONT);
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage(format: PageFormat::A4);
        $page->setFont(Font::custom('FS'), 12.0);
        $page->cell(w: 40.0, h: 8.0, text: 'Hello PDF/A-1');

        $bytes = $doc->output();
        self::assertStringStartsWith('%PDF-', $bytes);
    }

    public function testAlphaPngThrowsUnderA1B(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromFile(self::ALPHA_PNG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF\/A-1.*(transparency|alpha)/i');
        $doc->output();
    }

    public function testOpaquePngSucceedsUnderA1B(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromFile(self::OPAQUE_PNG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $bytes = $doc->output();
        self::assertStringStartsWith('%PDF-', $bytes);
    }

    public function testSvgOpacityThrowsUnderA1B(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromBytes(self::OPACITY_SVG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF\/A-1.*(opacity|transparency)/i');
        $doc->output();
    }

    public function testSvgMaskThrowsUnderA1B(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromBytes(self::MASK_SVG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF\/A-1.*(mask|transparency)/i');
        $doc->output();
    }

    public function testSvgFilterThrowsUnderA1B(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromBytes(self::FILTER_SVG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF\/A-1.*(filter|transparency)/i');
        $doc->output();
    }

    public function testAlphaPngThrowsUnderA1A(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A1A, 'en-US');
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromFile(self::ALPHA_PNG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF\/A-1.*(transparency|alpha)/i');
        $doc->output();
    }

    public function testNonPdfAAlphaPngSucceeds(): void
    {
        $doc = new Document();
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromFile(self::ALPHA_PNG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $bytes = $doc->output();
        self::assertStringStartsWith('%PDF-', $bytes);
    }

    public function testNonPdfASvgOpacitySucceeds(): void
    {
        $doc = new Document();
        $page = $doc->addPage(format: PageFormat::A4);
        $page->image(Image::fromBytes(self::OPACITY_SVG), x: 10.0, y: 10.0, w: 20.0, h: 20.0);

        $bytes = $doc->output();
        self::assertStringStartsWith('%PDF-', $bytes);
    }
}
