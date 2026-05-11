<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class StandardFontEngineTest extends TestCase
{
    private function engine(?Font $font = null): StandardFontEngine
    {
        $font ??= Font::helvetica();
        $metrics = (new MetricsRegistry())->metricsFor($font);
        return new StandardFontEngine($font, $metrics);
    }

    public function testFontReturnsBoundFont(): void
    {
        $font = Font::times()->bold();
        $engine = $this->engine($font);
        self::assertSame($font, $engine->font());
    }

    public function testMeasureMatchesFontMetricsAfterWinAnsiEncode(): void
    {
        $font = Font::helvetica();
        $metrics = (new MetricsRegistry())->metricsFor($font);
        $engine = new StandardFontEngine($font, $metrics);

        $text = 'Hello';
        $expected = $metrics->stringWidth(WinAnsiEncoder::encode($text), 12.0);
        self::assertSame($expected, $engine->measure($text, 12.0));
    }

    public function testMeasureEmptyReturnsZero(): void
    {
        self::assertSame(0.0, $this->engine()->measure('', 12.0));
    }

    public function testMeasureMapsEuroSign(): void
    {
        $engine = $this->engine();
        self::assertGreaterThan(0.0, $engine->measure("\u{20AC}", 12.0));
    }

    public function testEmitShowTextProducesTjOperator(): void
    {
        $stream = new ContentStream(842.0);
        $this->engine()->emitShowText($stream, 'Hi');
        self::assertStringContainsString('Tj', $stream->bytes());
    }

    public function testEmitShowTextNextLineProducesQuoteOperator(): void
    {
        $stream = new ContentStream(842.0);
        $this->engine()->emitShowTextNextLine($stream, 'Hi');
        self::assertStringContainsString("'", $stream->bytes());
    }

    public function testEmitShowTextEncodesInWinAnsi(): void
    {
        $streamEngine = new ContentStream(842.0);
        $this->engine()->emitShowText($streamEngine, 'a');
        self::assertStringContainsString('(a)', $streamEngine->bytes());
    }

    public function testSplitForceBreakIteratesByByte(): void
    {
        $engine = $this->engine();
        $size = 12.0;
        $aw = $engine->measure('a', $size);
        [$chunks, $widths] = $engine->splitForceBreak('abcd', $aw * 2 + 0.001, $size);
        self::assertSame(['ab', 'cd'], $chunks);
        self::assertCount(2, $widths);
    }

    public function testAscentDelegatesToMetrics(): void
    {
        $font = Font::helvetica();
        $metrics = (new MetricsRegistry())->metricsFor($font);
        $engine = new StandardFontEngine($font, $metrics);
        self::assertSame($metrics->ascentAt(12.0), $engine->ascentAt(12.0));
    }

    public function testDescentDelegatesToMetrics(): void
    {
        $font = Font::helvetica();
        $metrics = (new MetricsRegistry())->metricsFor($font);
        $engine = new StandardFontEngine($font, $metrics);
        self::assertSame($metrics->descentAt(12.0), $engine->descentAt(12.0));
    }

    public function testCapHeightDelegatesToMetrics(): void
    {
        $font = Font::helvetica();
        $metrics = (new MetricsRegistry())->metricsFor($font);
        $engine = new StandardFontEngine($font, $metrics);
        self::assertSame($metrics->capHeightAt(12.0), $engine->capHeightAt(12.0));
    }

    public function testXHeightScalesByThousand(): void
    {
        $font = Font::helvetica();
        $metrics = (new MetricsRegistry())->metricsFor($font);
        $engine = new StandardFontEngine($font, $metrics);
        $expected = $metrics->xHeight * 12.0 / 1000.0;
        self::assertSame($expected, $engine->xHeightAt(12.0));
    }

    public function testRegisterOnCallsStandardShortName(): void
    {
        $font = Font::times();
        $metrics = (new MetricsRegistry())->metricsFor($font);
        $engine = new StandardFontEngine($font, $metrics);
        $registry = new FontRegistry();
        self::assertSame('F1', $engine->registerOn($registry));
        self::assertSame('F1', $engine->registerOn($registry));
    }

    public function testUsageKeyEqualsPdfName(): void
    {
        $font = Font::courier()->bold();
        $engine = $this->engine($font);
        self::assertSame($font->pdfName(), $engine->usageKey());
    }
}
