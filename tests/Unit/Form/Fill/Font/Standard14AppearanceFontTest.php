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
}
