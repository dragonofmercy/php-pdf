<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\CompositeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use PHPUnit\Framework\TestCase;

final class CompositeFontEmitterTest extends TestCase
{
    private function ttf(): ParsedTtf
    {
        return new ParsedTtf(
            bytes: str_repeat("\x00", 256),
            postScriptName: 'FreeSans',
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
        );
    }

    public function testEmitsFiveIndirectObjectsInOrder(): void
    {
        $emitter = new CompositeFontEmitter();
        $result = $emitter->emit(
            font: $this->ttf(),
            type0Id: 10,
            cidFontId: 11,
            descriptorId: 12,
            fontFileId: 13,
            toUnicodeId: 14,
        );
        self::assertSame(['type0', 'cidFont', 'descriptor', 'fontFile', 'toUnicode'], array_keys($result));
        self::assertSame(10, $result['type0']->objectNumber);
        self::assertSame(11, $result['cidFont']->objectNumber);
        self::assertSame(12, $result['descriptor']->objectNumber);
        self::assertSame(13, $result['fontFile']->objectNumber);
        self::assertSame(14, $result['toUnicode']->objectNumber);
    }

    public function testType0DictReferencesCidFontAndToUnicode(): void
    {
        $emitter = new CompositeFontEmitter();
        $result = $emitter->emit($this->ttf(), 10, 11, 12, 13, 14);
        $bytes = $result['type0']->toBytes();
        self::assertStringContainsString('/Type /Font', $bytes);
        self::assertStringContainsString('/Subtype /Type0', $bytes);
        self::assertStringContainsString('/BaseFont /FreeSans', $bytes);
        self::assertStringContainsString('/Encoding /Identity-H', $bytes);
        self::assertStringContainsString('11 0 R', $bytes);
        self::assertStringContainsString('14 0 R', $bytes);
    }

    public function testFontDescriptorReferencesFontFile2(): void
    {
        $emitter = new CompositeFontEmitter();
        $result = $emitter->emit($this->ttf(), 10, 11, 12, 13, 14);
        $bytes = $result['descriptor']->toBytes();
        self::assertStringContainsString('/Type /FontDescriptor', $bytes);
        self::assertStringContainsString('/FontName /FreeSans', $bytes);
        self::assertStringContainsString('/Flags 32', $bytes);
        self::assertStringContainsString('13 0 R', $bytes);
    }

    public function testFontFile2StreamHasLength1AndFlateDecode(): void
    {
        $emitter = new CompositeFontEmitter();
        $result = $emitter->emit($this->ttf(), 10, 11, 12, 13, 14);
        $bytes = $result['fontFile']->toBytes();
        self::assertStringContainsString('/Length1 256', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testCidFontReferencesDescriptorAndContainsW(): void
    {
        $emitter = new CompositeFontEmitter();
        $result = $emitter->emit($this->ttf(), 10, 11, 12, 13, 14);
        $bytes = $result['cidFont']->toBytes();
        self::assertStringContainsString('/Subtype /CIDFontType2', $bytes);
        self::assertStringContainsString('/BaseFont /FreeSans', $bytes);
        self::assertStringContainsString('12 0 R', $bytes);
        self::assertStringContainsString('/CIDToGIDMap /Identity', $bytes);
        self::assertStringContainsString('/W ', $bytes);
    }
}
