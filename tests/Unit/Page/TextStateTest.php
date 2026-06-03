<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Page\TextState;
use PHPUnit\Framework\TestCase;

final class TextStateTest extends TestCase
{
    private function makeState(?Font $font = null, ?float $size = null): TextState
    {
        return new TextState(new MetricsRegistry(), null, $font, $size);
    }

    public function testGetFontThrowsWhenUnset(): void
    {
        $this->expectException(PdfException::class);
        $this->makeState()->getFont();
    }

    public function testGetFontSizeThrowsWhenUnset(): void
    {
        $this->expectException(PdfException::class);
        $this->makeState()->getFontSize();
    }

    public function testSetFontThenGetFontAndSize(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 12.0);
        self::assertSame(12.0, $s->getFontSize());
        self::assertSame('Helvetica', $s->getFont()->pdfName());
    }

    public function testSetFontRejectsNonPositiveSize(): void
    {
        $s = $this->makeState();
        $this->expectException(PdfException::class);
        $s->setFont(Font::helvetica(), 0.0);
    }

    public function testSetFontNullSizeInheritsCurrentSize(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 12.0);
        $s->setFont(Font::times(), null);
        self::assertSame(12.0, $s->getFontSize());
        self::assertSame('Times-Roman', $s->getFont()->pdfName());
    }

    public function testSetFontNullSizeThrowsWhenNoPreviousFont(): void
    {
        $s = $this->makeState();
        $this->expectException(PdfException::class);
        $s->setFont(Font::helvetica(), null);
    }

    public function testDefaultFontRequiresBothFontAndSize(): void
    {
        $this->expectException(PdfException::class);
        $this->makeState(Font::helvetica(), null);
    }

    public function testDefaultFontNegativeSizeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->makeState(Font::helvetica(), -1.0);
    }

    public function testDefaultFontSetsState(): void
    {
        $s = $this->makeState(Font::helvetica(), 10.0);
        self::assertSame(10.0, $s->getFontSize());
        self::assertSame('Helvetica', $s->getFont()->pdfName());
    }

    public function testSetLeadingIsReturnedByCustomLeading(): void
    {
        $s = $this->makeState();
        self::assertNull($s->customLeading());
        $s->setLeading(15.0);
        self::assertSame(15.0, $s->customLeading());
    }

    public function testSetFontResetsLeading(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 12.0);
        $s->setLeading(15.0);
        $s->setFont(Font::times(), 10.0);
        self::assertNull($s->customLeading());
    }

    public function testSetFontSizeChangesSizeKeepsFont(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 12.0);
        $s->setFontSize(18.0);
        self::assertSame(18.0, $s->getFontSize());
        self::assertSame('Helvetica', $s->getFont()->pdfName());
    }

    public function testSetFontSizeRejectsNonPositiveSize(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 12.0);
        $this->expectException(PdfException::class);
        $s->setFontSize(0.0);
    }

    public function testSetFontSizeThrowsWhenNoFontSet(): void
    {
        $s = $this->makeState();
        $this->expectException(PdfException::class);
        $s->setFontSize(12.0);
    }

    public function testSetFontSizeResetsLeading(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 12.0);
        $s->setLeading(15.0);
        $s->setFontSize(18.0);
        self::assertNull($s->customLeading());
    }

    public function testCaptureRestoreRoundTrips(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 11.0);
        $snapshot = $s->capture();
        $s->setFont(Font::times(), 20.0);
        $s->restore($snapshot);
        self::assertSame(11.0, $s->getFontSize());
        self::assertSame('Helvetica', $s->getFont()->pdfName());
    }

    public function testCaptureRestorePreservesLeading(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 11.0);
        $s->setLeading(14.0);
        $snapshot = $s->capture();
        $s->setFont(Font::times(), 20.0);
        $s->restore($snapshot);
        self::assertSame(14.0, $s->customLeading());
    }

    public function testActiveEngineThrowsWhenNoFont(): void
    {
        $this->expectException(PdfException::class);
        $this->makeState()->activeEngine();
    }

    public function testActiveEngineReturnsEngineAfterSetFont(): void
    {
        $s = $this->makeState();
        $s->setFont(Font::helvetica(), 12.0);
        // activeEngine() must not throw - just calling it is the assertion
        $engine = $s->activeEngine();
        self::assertSame('Helvetica', $engine->font()->pdfName());
    }

    public function testMeasureMaxLineWidthUsesLongestLine(): void
    {
        $s = $this->makeState();
        $w = $s->measureMaxLineWidthPt("a\nlonger line", Font::helvetica(), 12.0);
        self::assertGreaterThan(0.0, $w);
    }

    public function testMeasureEmptyStringReturnsZero(): void
    {
        $s = $this->makeState();
        $w = $s->measureMaxLineWidthPt('', Font::helvetica(), 12.0);
        self::assertSame(0.0, $w);
    }

    public function testCurrentFontAndCurrentSizeAccessors(): void
    {
        $s = $this->makeState();
        self::assertNull($s->currentFont());
        self::assertNull($s->currentSize());
        $s->setFont(Font::courier(), 9.0);
        self::assertNotNull($s->currentFont());
        self::assertSame(9.0, $s->currentSize());
    }
}
