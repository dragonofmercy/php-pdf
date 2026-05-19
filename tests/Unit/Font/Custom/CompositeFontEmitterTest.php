<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\CompositeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\SubsettedFont;
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

    private function subset(): SubsettedFont
    {
        return new SubsettedFont(str_repeat("\x01", 128), 'ABCDEF+FreeSans');
    }

    public function testEmitsFiveIndirectObjectsInOrder(): void
    {
        $result = (new CompositeFontEmitter())->emit(
            font: $this->ttf(),
            subset: $this->subset(),
            type0Id: 10,
            cidFontId: 11,
            descriptorId: 12,
            fontFileId: 13,
            toUnicodeId: 14,
        );
        self::assertSame(['type0', 'cidFont', 'descriptor', 'fontFile', 'toUnicode'], array_keys($result));
        self::assertSame(10, $result['type0']->objectNumber);
        self::assertSame(14, $result['toUnicode']->objectNumber);
    }

    public function testBaseFontAndFontNameCarrySubsetPrefix(): void
    {
        $result = (new CompositeFontEmitter())->emit($this->ttf(), $this->subset(), 10, 11, 12, 13, 14);
        self::assertStringContainsString('/BaseFont /ABCDEF+FreeSans', $result['type0']->toBytes());
        self::assertStringContainsString('/BaseFont /ABCDEF+FreeSans', $result['cidFont']->toBytes());
        self::assertStringContainsString('/FontName /ABCDEF+FreeSans', $result['descriptor']->toBytes());
    }

    public function testFontFile2UsesSubsettedBytesLength(): void
    {
        $result = (new CompositeFontEmitter())->emit($this->ttf(), $this->subset(), 10, 11, 12, 13, 14);
        $bytes = $result['fontFile']->toBytes();
        self::assertStringContainsString('/Length1 128', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testCidFontStillReferencesDescriptorAndW(): void
    {
        $result = (new CompositeFontEmitter())->emit($this->ttf(), $this->subset(), 10, 11, 12, 13, 14);
        $bytes = $result['cidFont']->toBytes();
        self::assertStringContainsString('/Subtype /CIDFontType2', $bytes);
        self::assertStringContainsString('/CIDToGIDMap /Identity', $bytes);
        self::assertStringContainsString('/W ', $bytes);
        self::assertStringContainsString('12 0 R', $bytes);
    }
}
