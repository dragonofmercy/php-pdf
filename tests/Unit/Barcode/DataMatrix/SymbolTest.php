<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\DataMatrix;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix\Symbol;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class SymbolTest extends TestCase
{
    public function testTableHasTwentyFourSquares(): void
    {
        self::assertCount(24, Symbol::all());
    }

    public function testSmallestSymbolIs10x10(): void
    {
        $s = Symbol::all()[0];
        self::assertSame(10, $s->moduleRows);
        self::assertSame(10, $s->moduleCols);
        self::assertSame(3, $s->dataCodewords);
        self::assertSame(5, $s->ecCodewords);
        self::assertSame(8, $s->dataRegionRows);
        self::assertSame(8, $s->dataRegionCols);
        self::assertSame(1, $s->regionRows);
        self::assertSame(1, $s->regionCols);
        self::assertSame(1, $s->ecBlocks);
    }

    public function testLargestSymbolIs144x144(): void
    {
        $s = Symbol::all()[23];
        self::assertSame(144, $s->moduleRows);
        self::assertSame(144, $s->moduleCols);
        self::assertSame(1558, $s->dataCodewords);
        self::assertSame(620, $s->ecCodewords);
        self::assertSame(22, $s->dataRegionRows);
        self::assertSame(22, $s->dataRegionCols);
        self::assertSame(6, $s->regionRows);
        self::assertSame(6, $s->regionCols);
        self::assertSame(10, $s->ecBlocks);
    }

    public function testPickSmallestPicksFirstThatFits(): void
    {
        // 3 codewords fits 10x10.
        self::assertSame(10, Symbol::pickSmallest(3)->moduleRows);
        // 4 codewords requires 12x12 (3 data, 5 ec; 12x12 has 5 data).
        self::assertSame(12, Symbol::pickSmallest(4)->moduleRows);
        // 5 codewords still fits 12x12.
        self::assertSame(12, Symbol::pickSmallest(5)->moduleRows);
        // 6 requires 14x14.
        self::assertSame(14, Symbol::pickSmallest(6)->moduleRows);
        // 1558 (max) fits 144x144.
        self::assertSame(144, Symbol::pickSmallest(1558)->moduleRows);
    }

    public function testPickSmallestThrowsWhenOverCapacity(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/DataMatrix data too large/');
        Symbol::pickSmallest(1559);
    }

    public function testMultiRegionSymbolsHaveCorrectRegionCounts(): void
    {
        // 32x32 = 2x2 regions of 14x14 modules
        $s32 = Symbol::pickByModuleSize(32);
        self::assertSame(2, $s32->regionRows);
        self::assertSame(2, $s32->regionCols);
        self::assertSame(14, $s32->dataRegionRows);

        // 64x64 = 4x4 regions of 14x14
        $s64 = Symbol::pickByModuleSize(64);
        self::assertSame(4, $s64->regionRows);
        self::assertSame(4, $s64->regionCols);

        // 120x120 = 6x6 regions of 18x18
        $s120 = Symbol::pickByModuleSize(120);
        self::assertSame(6, $s120->regionRows);
        self::assertSame(6, $s120->regionCols);
        self::assertSame(18, $s120->dataRegionRows);
    }

    public function testTotalCodewordsEqualsDataPlusEc(): void
    {
        foreach (Symbol::all() as $s) {
            self::assertSame(
                $s->dataCodewords + $s->ecCodewords,
                $s->totalCodewords(),
                "Symbol {$s->moduleRows}x{$s->moduleCols} total mismatch",
            );
        }
    }
}
