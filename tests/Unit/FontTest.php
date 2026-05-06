<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Font;
use PHPUnit\Framework\TestCase;

final class FontTest extends TestCase
{
    public function testHelveticaDefaultsToRegular(): void
    {
        self::assertSame('Helvetica', Font::helvetica()->pdfName());
    }

    public function testHelveticaBold(): void
    {
        self::assertSame('Helvetica-Bold', Font::helvetica()->bold()->pdfName());
    }

    public function testHelveticaItalicUsesObliqueSuffix(): void
    {
        self::assertSame('Helvetica-Oblique', Font::helvetica()->italic()->pdfName());
    }

    public function testHelveticaBoldItalic(): void
    {
        self::assertSame('Helvetica-BoldOblique', Font::helvetica()->bold()->italic()->pdfName());
    }

    public function testBoldItalicCommutative(): void
    {
        self::assertSame(
            Font::helvetica()->bold()->italic()->pdfName(),
            Font::helvetica()->italic()->bold()->pdfName(),
        );
    }

    public function testTimesRegularUsesRomanSuffix(): void
    {
        self::assertSame('Times-Roman', Font::times()->pdfName());
    }

    public function testTimesBold(): void
    {
        self::assertSame('Times-Bold', Font::times()->bold()->pdfName());
    }

    public function testTimesItalicUsesItalicSuffix(): void
    {
        self::assertSame('Times-Italic', Font::times()->italic()->pdfName());
    }

    public function testTimesBoldItalic(): void
    {
        self::assertSame('Times-BoldItalic', Font::times()->bold()->italic()->pdfName());
    }

    public function testCourierRegular(): void
    {
        self::assertSame('Courier', Font::courier()->pdfName());
    }

    public function testCourierBold(): void
    {
        self::assertSame('Courier-Bold', Font::courier()->bold()->pdfName());
    }

    public function testCourierItalicUsesObliqueSuffix(): void
    {
        self::assertSame('Courier-Oblique', Font::courier()->italic()->pdfName());
    }

    public function testCourierBoldItalic(): void
    {
        self::assertSame('Courier-BoldOblique', Font::courier()->bold()->italic()->pdfName());
    }

    public function testBoldIdempotent(): void
    {
        self::assertSame('Helvetica-Bold', Font::helvetica()->bold()->bold()->pdfName());
    }

    public function testFactoriesReturnFreshInstances(): void
    {
        self::assertNotSame(Font::helvetica(), Font::helvetica());
    }
}
