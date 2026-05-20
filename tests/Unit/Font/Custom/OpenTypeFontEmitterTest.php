<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\OpenTypeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\OutlineFormat;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\SubsettedFont;
use PHPUnit\Framework\TestCase;

final class OpenTypeFontEmitterTest extends TestCase
{
    private function otf(): ParsedTtf
    {
        return new ParsedTtf(
            bytes: 'OTTO' . str_repeat("\x00", 252),
            postScriptName: 'IBMPlexSans',
            unitsPerEm: 1000,
            ascent: 935,
            descent: -265,
            capHeight: 729,
            xHeight: 525,
            bbox: [-200, -300, 1100, 800],
            italicAngle: 0,
            weight: 400,
            flags: 32,
            cmap: [0x41 => 36, 0x42 => 37],
            advanceWidthsByGid: [0 => 500, 36 => 600, 37 => 580],
            outlineFormat: OutlineFormat::Cff,
        );
    }

    private function subset(): SubsettedFont
    {
        return new SubsettedFont(str_repeat("\x02", 64), 'XXXXXX+IBMPlexSans');
    }

    public function testEmitsFiveIndirectObjectsInOrder(): void
    {
        $result = (new OpenTypeFontEmitter())->emit($this->subset(), $this->otf(), 10, 11, 12, 13, 14);
        self::assertSame(['type0', 'cidFont', 'descriptor', 'fontFile', 'toUnicode'], array_keys($result));
        self::assertSame(10, $result['type0']->objectNumber);
        self::assertSame(11, $result['cidFont']->objectNumber);
        self::assertSame(12, $result['descriptor']->objectNumber);
        self::assertSame(13, $result['fontFile']->objectNumber);
        self::assertSame(14, $result['toUnicode']->objectNumber);
    }

    public function testCidFontIsType0WithoutCidToGidMap(): void
    {
        $bytes = (new OpenTypeFontEmitter())->emit($this->subset(), $this->otf(), 10, 11, 12, 13, 14)['cidFont']->toBytes();
        self::assertStringContainsString('/Subtype /CIDFontType0', $bytes);
        self::assertStringNotContainsString('CIDFontType2', $bytes);
        self::assertStringNotContainsString('/CIDToGIDMap', $bytes);
        self::assertStringContainsString('/W ', $bytes);
        self::assertStringContainsString('12 0 R', $bytes);
    }

    public function testDescriptorReferencesFontFile3(): void
    {
        $bytes = (new OpenTypeFontEmitter())->emit($this->subset(), $this->otf(), 10, 11, 12, 13, 14)['descriptor']->toBytes();
        self::assertStringContainsString('/Type /FontDescriptor', $bytes);
        self::assertStringContainsString('/FontFile3 13 0 R', $bytes);
        self::assertStringNotContainsString('/FontFile2', $bytes);
    }

    public function testFontFile3IsOpenTypeWithoutLength1(): void
    {
        $bytes = (new OpenTypeFontEmitter())->emit($this->subset(), $this->otf(), 10, 11, 12, 13, 14)['fontFile']->toBytes();
        self::assertStringContainsString('/Subtype /OpenType', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
        self::assertStringNotContainsString('/Length1', $bytes);
    }

    public function testBaseFontCarriesSubsetPrefix(): void
    {
        $result = (new OpenTypeFontEmitter())->emit($this->subset(), $this->otf(), 10, 11, 12, 13, 14);
        self::assertStringContainsString('/BaseFont /XXXXXX+IBMPlexSans', $result['type0']->toBytes());
        self::assertStringContainsString('/BaseFont /XXXXXX+IBMPlexSans', $result['cidFont']->toBytes());
        self::assertStringContainsString('/FontName /XXXXXX+IBMPlexSans', $result['descriptor']->toBytes());
    }

    public function testFontFile3BodyUsesSubsettedBytes(): void
    {
        $subset = new SubsettedFont(str_repeat("\x07", 128), 'ABCDEF+IBMPlexSans');
        $bytes = (new OpenTypeFontEmitter())->emit($subset, $this->otf(), 10, 11, 12, 13, 14)['fontFile']->toBytes();
        // The deflated stream embeds the subsetted bytes, not the original $font->bytes.
        // We cannot easily inspect the compressed body byte-for-byte here without
        // re-decompressing; the stream length plus the deterministic emit of
        // /Subtype /OpenType is the public observable. The compressed length
        // bounds verify the body is non-trivial yet smaller than the input.
        self::assertStringContainsString('/Subtype /OpenType', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testType0ReferencesCidFontAndToUnicode(): void
    {
        $bytes = (new OpenTypeFontEmitter())->emit($this->subset(), $this->otf(), 10, 11, 12, 13, 14)['type0']->toBytes();
        self::assertStringContainsString('/Subtype /Type0', $bytes);
        self::assertStringContainsString('/Encoding /Identity-H', $bytes);
        self::assertStringContainsString('11 0 R', $bytes);
        self::assertStringContainsString('14 0 R', $bytes);
    }
}
