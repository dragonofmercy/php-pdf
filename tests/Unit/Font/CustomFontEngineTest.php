<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\CustomFontEngine;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class CustomFontEngineTest extends TestCase
{
    private function parsedTtf(): ParsedTtf
    {
        return new ParsedTtf(
            bytes: '',
            postScriptName: 'Synthetic-Regular',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            capHeight: 700,
            xHeight: 500,
            bbox: [0, -200, 1000, 800],
            italicAngle: 0,
            weight: 400,
            flags: 32,
            cmap: [0x41 => 1, 0x42 => 2],
            advanceWidthsByGid: [0 => 0, 1 => 500, 2 => 600],
        );
    }

    private function engine(?Font $font = null, ?ParsedTtf $ttf = null): CustomFontEngine
    {
        $font ??= Font::custom('Synthetic');
        $ttf ??= $this->parsedTtf();
        return new CustomFontEngine($font, $ttf);
    }

    public function testFontReturnsBoundFont(): void
    {
        $font = Font::custom('Inter')->bold();
        self::assertSame($font, $this->engine($font)->font());
    }

    public function testMeasureUsesCmapAndAdvanceWidths(): void
    {
        self::assertSame(13.2, $this->engine()->measure('AB', 12.0));
    }

    public function testMeasureEmptyReturnsZero(): void
    {
        self::assertSame(0.0, $this->engine()->measure('', 12.0));
    }

    public function testMeasureUnmappableCodepointCountsAsGid0(): void
    {
        self::assertSame(0.0, $this->engine()->measure('Z', 12.0));
    }

    public function testEmitShowTextProducesHexTjOperator(): void
    {
        $stream = new ContentStream(842.0);
        $this->engine()->emitShowText($stream, 'A');
        self::assertStringContainsString('<0001> Tj', $stream->bytes());
    }

    public function testEmitShowTextNextLineProducesHexQuoteOperator(): void
    {
        $stream = new ContentStream(842.0);
        $this->engine()->emitShowTextNextLine($stream, 'B');
        self::assertStringContainsString("<0002> '", $stream->bytes());
    }

    public function testEmitShowTextHexIsUppercase(): void
    {
        $stream = new ContentStream(842.0);
        $ttf = new ParsedTtf(
            bytes: '',
            postScriptName: 'Synthetic',
            unitsPerEm: 1000,
            ascent: 0,
            descent: 0,
            capHeight: 0,
            xHeight: 0,
            bbox: [0, 0, 0, 0],
            italicAngle: 0,
            weight: 400,
            flags: 0,
            cmap: [0x41 => 0xAB],
            advanceWidthsByGid: [0 => 0, 0xAB => 500],
        );
        $this->engine(ttf: $ttf)->emitShowText($stream, 'A');
        self::assertStringContainsString('<00AB>', $stream->bytes());
    }

    public function testSplitForceBreakIteratesByUtf8Codepoint(): void
    {
        $ttf = new ParsedTtf(
            bytes: '',
            postScriptName: 'Synthetic',
            unitsPerEm: 1000,
            ascent: 0,
            descent: 0,
            capHeight: 0,
            xHeight: 0,
            bbox: [0, 0, 0, 0],
            italicAngle: 0,
            weight: 400,
            flags: 0,
            cmap: [0x41 => 1, 0xE9 => 2],
            advanceWidthsByGid: [0 => 0, 1 => 500, 2 => 500],
        );
        $engine = $this->engine(ttf: $ttf);
        $size = 12.0;
        $widthOne = $engine->measure('A', $size);
        [$chunks, $widths] = $engine->splitForceBreak("A\u{00E9}A\u{00E9}", $widthOne * 2 + 0.001, $size);
        self::assertSame(["A\u{00E9}", "A\u{00E9}"], $chunks);
        self::assertCount(2, $widths);
    }

    public function testAscentScalesByUnitsPerEm(): void
    {
        self::assertSame(9.6, $this->engine()->ascentAt(12.0));
    }

    public function testDescentScalesByUnitsPerEm(): void
    {
        self::assertSame(-2.4, $this->engine()->descentAt(12.0));
    }

    public function testCapHeightScalesByUnitsPerEm(): void
    {
        self::assertSame(8.4, $this->engine()->capHeightAt(12.0));
    }

    public function testXHeightScalesByUnitsPerEm(): void
    {
        self::assertSame(6.0, $this->engine()->xHeightAt(12.0));
    }

    public function testRegisterOnCallsShortNameForCustomWithKey(): void
    {
        $font = Font::custom('Synthetic');
        $engine = $this->engine($font);
        $registry = new FontRegistry();
        self::assertSame('F1', $engine->registerOn($registry));
        self::assertSame('F1', $engine->registerOn($registry));
        $regs = $registry->customRegistrations();
        self::assertSame('Synthetic:Synthetic-Regular', $regs['F1']->toRegistryKey());
    }

    public function testUsageKeyEqualsCustomFontKeyRegistryKey(): void
    {
        $font = Font::custom('Synthetic');
        $engine = $this->engine($font);
        self::assertSame(
            (new CustomFontKey('Synthetic', 'Synthetic-Regular'))->toRegistryKey(),
            $engine->usageKey(),
        );
    }
}
