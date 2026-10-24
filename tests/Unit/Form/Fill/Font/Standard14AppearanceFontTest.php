<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\Form\Fill\Font\Standard14AppearanceFont;
use DragonOfMercy\PhpPdf\Form\Fill\PdfLiteralEscape;
use PHPUnit\Framework\TestCase;

final class Standard14AppearanceFontTest extends TestCase
{
    public function testMeasureWidthMatchesMetricsRegistry(): void
    {
        $font = Font::helvetica();
        $metrics = new MetricsRegistry();
        $apFont = new Standard14AppearanceFont($font, $metrics);

        $expected = $metrics->metricsFor($font)->stringWidth(WinAnsiEncoder::encode('Hello'), 10.0);
        self::assertSame($expected, $apFont->measureWidth('Hello', 10.0));
    }

    public function testEncodeShowOperandIsEscapedWinAnsiLiteral(): void
    {
        $apFont = new Standard14AppearanceFont(Font::helvetica(), new MetricsRegistry());
        $expected = '(' . PdfLiteralEscape::escape(WinAnsiEncoder::encode('a(b)')) . ')';
        self::assertSame($expected, $apFont->encodeShowOperand('a(b)'));
    }

    public function testEncodeShowOperandUsesTheEncodingMapWhenGiven(): void
    {
        // PDFDocEncoding puts „ at 0x8C and • at 0x80, where WinAnsi has Œ and €.
        $map = [ord('a') => ord('a'), ord('(') => ord('('), 0x201E => 0x8C, 0x2022 => 0x80];
        $apFont = new Standard14AppearanceFont(Font::helvetica(), new MetricsRegistry(), $map);

        self::assertSame("(\x8Ca\x80\\()", $apFont->encodeShowOperand('„a•('));
    }

    public function testEncodeShowOperandMapsUnmappedCodepointsToQuestionMark(): void
    {
        $apFont = new Standard14AppearanceFont(Font::helvetica(), new MetricsRegistry(), [ord('a') => ord('a')]);

        self::assertSame('(a?)', $apFont->encodeShowOperand('a€'));
    }

    public function testMeasureWidthIgnoresTheEncodingMap(): void
    {
        $plain = new Standard14AppearanceFont(Font::helvetica(), new MetricsRegistry());
        $mapped = new Standard14AppearanceFont(Font::helvetica(), new MetricsRegistry(), [0x201E => 0x8C]);

        self::assertSame($plain->measureWidth('„x“', 10.0), $mapped->measureWidth('„x“', 10.0));
    }
}
