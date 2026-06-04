<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\CustomFontEngine;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class JustifiedLineTest extends TestCase
{
    public function testStandardEngineEmitsTjArrayWithGapAdjustments(): void
    {
        $font = Font::helvetica();
        $engine = new StandardFontEngine($font, (new MetricsRegistry())->metricsFor($font));
        $stream = new ContentStream(842.0);
        // two segments => one gap; extraPerGap 6pt at size 12 => adj = -6/12*1000 = -500
        $engine->emitJustifiedLine($stream, ['foo ', 'bar'], 6.0, 12.0);
        // ContentStream prepends a Y-flip CTM; check just the TJ instruction
        self::assertStringContainsString("[(foo )-500(bar)] TJ\n", $stream->bytes());
    }

    public function testCustomEngineEmitsTjArrayWithHexSegmentsAndGapAdjustment(): void
    {
        $ttf = new ParsedTtf(
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
        $usage = new GlyphUsage();
        $font = Font::custom('Synthetic');
        $engine = new CustomFontEngine($font, $ttf, $usage);
        $stream = new ContentStream(842.0);
        // two segments ('AB ' and 'AB'); one gap; extraPerGap 6pt at size 12 => adj = -500
        // cmap: A=>1, B=>2; space unmapped => gid 0
        $engine->emitJustifiedLine($stream, ['AB ', 'AB'], 6.0, 12.0);
        $bytes = $stream->bytes();
        // ContentStream prepends a Y-flip CTM; the TJ array uses hex < > elements
        self::assertStringContainsString('[<', $bytes);
        // Must contain the -500 adjustment
        self::assertStringContainsString('-500', $bytes);
        // Must close with ] TJ
        self::assertStringContainsString('] TJ', $bytes);
        // Must contain >-500< (hex segment, gap, next hex segment)
        self::assertStringContainsString('>-500<', $bytes);
    }
}
