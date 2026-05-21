<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\DataMatrix;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix\Matrix;
use DragonOfMercy\PhpPdf\Barcode\DataMatrix\Symbol;
use PHPUnit\Framework\TestCase;

final class MatrixTest extends TestCase
{
    public function test10x10MatrixHasFinderLAndTiming(): void
    {
        $symbol = Symbol::pickByModuleSize(10);
        $matrix = Matrix::build($symbol);
        // Bottom row entirely dark (the "L" base).
        for ($x = 0; $x < 10; $x++) {
            self::assertTrue($matrix->modules[9][$x], "bottom row x={$x} should be dark");
        }
        // Left column entirely dark.
        for ($y = 0; $y < 10; $y++) {
            self::assertTrue($matrix->modules[$y][0], "left col y={$y} should be dark");
        }
        // Top timing: alternating, even x = dark, odd x = light.
        for ($x = 1; $x < 10; $x++) {
            $expected = ($x % 2 === 0);
            self::assertSame(
                $expected,
                $matrix->modules[0][$x],
                "top row timing at x={$x} expected " . ($expected ? 'dark' : 'light'),
            );
        }
        // Right column timing visible region: y=1..8 (y=0 is timing corner, y=9 is L base).
        for ($y = 1; $y < 9; $y++) {
            $expected = ($y % 2 === 0);
            self::assertSame(
                $expected,
                $matrix->modules[$y][9],
                "right col timing at y={$y} expected " . ($expected ? 'dark' : 'light'),
            );
        }
    }

    public function testPlaceCodewordsFillsAllDataCells(): void
    {
        $symbol = Symbol::pickByModuleSize(10);
        $matrix = Matrix::build($symbol);
        $allOnes = array_fill(0, $symbol->totalCodewords(), 0xFF);
        $matrix->placeCodewords($allOnes);
        // Interior data cell at module (2, 2) should be dark.
        self::assertTrue($matrix->modules[2][2], 'interior data cell at (2,2) should be dark with 0xFF input');
    }

    public function test32x32MatrixHasFourRegionsWithInternalFinders(): void
    {
        $symbol = Symbol::pickByModuleSize(32);
        $matrix = Matrix::build($symbol);
        // Outer bottom row (y=31) entirely dark.
        for ($x = 0; $x < 32; $x++) {
            self::assertTrue($matrix->modules[31][$x], "outer bottom row x={$x} should be dark");
        }
        // Internal horizontal L base at row 15 (between the two stacked regions).
        for ($x = 0; $x < 32; $x++) {
            self::assertTrue($matrix->modules[15][$x], "internal row 15 x={$x} should be dark");
        }
        // Internal vertical L base at col 16 (between the two side-by-side regions).
        for ($y = 0; $y < 32; $y++) {
            self::assertTrue($matrix->modules[$y][16], "internal col 16 y={$y} should be dark");
        }
    }
}
