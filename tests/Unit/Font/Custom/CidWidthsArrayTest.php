<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\CidWidthsArray;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use PHPUnit\Framework\TestCase;

final class CidWidthsArrayTest extends TestCase
{
    /** @param array<int, int> $widths */
    private function makeFont(array $widths, int $unitsPerEm = 1000): ParsedTtf
    {
        return new ParsedTtf(
            bytes: '', postScriptName: 'Test', unitsPerEm: $unitsPerEm,
            ascent: 800, descent: -200, capHeight: 700, xHeight: 500,
            bbox: [0, 0, 1000, 1000], italicAngle: 0, weight: 400, flags: 32,
            cmap: [], advanceWidthsByGid: $widths,
        );
    }

    public function testEmptyFontProducesEmptyArray(): void
    {
        self::assertSame('[]', CidWidthsArray::generate($this->makeFont([])));
    }

    public function testHeterogeneousRunEmitsExplicitArray(): void
    {
        $w = CidWidthsArray::generate($this->makeFont([
            10 => 500, 11 => 600, 12 => 700,
        ]));
        self::assertSame('[10 [500 600 700]]', $w);
    }

    public function testConstantRunOfFourOrMoreUsesRangeForm(): void
    {
        $w = CidWidthsArray::generate($this->makeFont([
            5 => 400, 6 => 400, 7 => 400, 8 => 400,
        ]));
        self::assertSame('[5 8 400]', $w);
    }

    public function testMonospaceFontUsesSingleRange(): void
    {
        $w = CidWidthsArray::generate($this->makeFont([
            0 => 500, 1 => 500, 2 => 500, 3 => 500,
        ]));
        self::assertSame('[0 3 500]', $w);
    }

    public function testAlternationBetweenConstantAndHeterogeneous(): void
    {
        $w = CidWidthsArray::generate($this->makeFont([
            10 => 100, 11 => 200, 12 => 300,
            13 => 500, 14 => 500, 15 => 500, 16 => 500,
        ]));
        self::assertSame('[10 [100 200 300] 13 16 500]', $w);
    }

    public function testScalesWidthsToOneThousandEmUnits(): void
    {
        $w = CidWidthsArray::generate($this->makeFont([
            0 => 1024, 1 => 1024, 2 => 1024, 3 => 1024,
        ], unitsPerEm: 2048));
        self::assertSame('[0 3 500]', $w);
    }
}
