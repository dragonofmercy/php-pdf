<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\ToUnicodeCMap;
use PHPUnit\Framework\TestCase;

final class ToUnicodeCMapTest extends TestCase
{
    /** @param array<int, int> $cmap */
    private function makeFont(array $cmap): ParsedTtf
    {
        return new ParsedTtf(
            bytes: '', postScriptName: 'Test', unitsPerEm: 1000,
            ascent: 800, descent: -200, capHeight: 700, xHeight: 500,
            bbox: [0, 0, 1000, 1000], italicAngle: 0, weight: 400, flags: 32,
            cmap: $cmap, advanceWidthsByGid: [],
        );
    }

    public function testHeaderAndFooterAreValidPostscript(): void
    {
        $cmap = ToUnicodeCMap::generate($this->makeFont([0x41 => 36]));
        self::assertStringContainsString('/CIDInit /ProcSet findresource begin', $cmap);
        self::assertStringContainsString('begincmap', $cmap);
        self::assertStringContainsString('1 begincodespacerange', $cmap);
        self::assertStringContainsString('<0000> <FFFF>', $cmap);
        self::assertStringContainsString('endcodespacerange', $cmap);
        self::assertStringContainsString('endcmap', $cmap);
    }

    public function testEmitsOneBfcharEntryPerGid(): void
    {
        $cmap = ToUnicodeCMap::generate($this->makeFont([
            0x41 => 36,
            0x42 => 37,
            0xE9 => 233,
        ]));
        self::assertStringContainsString('3 beginbfchar', $cmap);
        self::assertStringContainsString('<0024> <0041>', $cmap);
        self::assertStringContainsString('<0025> <0042>', $cmap);
        self::assertStringContainsString('<00E9> <00E9>', $cmap);
        self::assertStringContainsString('endbfchar', $cmap);
    }

    public function testGid0IsNotEmitted(): void
    {
        $cmap = ToUnicodeCMap::generate($this->makeFont([
            0x41 => 36,
            0x42 => 0,
        ]));
        self::assertStringNotContainsString('<0000> <0042>', $cmap);
        self::assertStringContainsString('1 beginbfchar', $cmap);
    }

    public function testNonBmpCodepointEncodedAsSurrogatePair(): void
    {
        $cmap = ToUnicodeCMap::generate($this->makeFont([0x1F600 => 9000]));
        self::assertStringContainsString('<2328> <D83DDE00>', $cmap);
    }

    public function testHexUppercase(): void
    {
        $cmap = ToUnicodeCMap::generate($this->makeFont([0xab => 200]));
        self::assertStringContainsString('<00C8> <00AB>', $cmap);
    }
}
