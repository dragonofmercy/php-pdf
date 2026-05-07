<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\Qr\Matrix;
use PHPUnit\Framework\TestCase;

final class MatrixTest extends TestCase
{
    public function testV1HasSize21(): void
    {
        $m = Matrix::buildEmpty(1);
        self::assertCount(21, $m->modules);
        self::assertCount(21, $m->modules[0]);
    }

    public function testFinderPatternsArePlaced(): void
    {
        $m = Matrix::buildEmpty(1);
        // Top-left finder: rows 0..6, cols 0..6. Outer ring is dark.
        for ($i = 0; $i < 7; $i++) {
            self::assertTrue($m->modules[0][$i], "top row finder col {$i}");
            self::assertTrue($m->modules[6][$i], "bottom row finder col {$i}");
            self::assertTrue($m->modules[$i][0], "left col finder row {$i}");
            self::assertTrue($m->modules[$i][6], "right col finder row {$i}");
        }
        // Centre 3x3 of finder is dark.
        for ($i = 2; $i <= 4; $i++) {
            for ($j = 2; $j <= 4; $j++) {
                self::assertTrue($m->modules[$i][$j], "centre square at ({$i},{$j})");
            }
        }
        // Inner ring (between centre and outer) is light.
        self::assertFalse($m->modules[1][1]);
    }

    public function testTimingPatternsAlternate(): void
    {
        $m = Matrix::buildEmpty(1);
        // Row 6 between cols 8 and 12 alternates: 8 dark, 9 light, 10 dark, 11 light, 12 dark.
        self::assertTrue($m->modules[6][8]);
        self::assertFalse($m->modules[6][9]);
        self::assertTrue($m->modules[6][10]);
        self::assertFalse($m->modules[6][11]);
        self::assertTrue($m->modules[6][12]);
    }

    public function testDarkModuleAtFixedPositionV1(): void
    {
        // Dark module at (4*1+9, 8) = (13, 8).
        $m = Matrix::buildEmpty(1);
        self::assertTrue($m->modules[13][8]);
        self::assertTrue($m->reserved[13][8]);
    }

    public function testDataAreaIsNotReserved(): void
    {
        // A few known data-area positions for V1.
        $m = Matrix::buildEmpty(1);
        self::assertFalse($m->reserved[20][20]); // bottom-right corner is data area
    }

    public function testV10HasSize57(): void
    {
        $m = Matrix::buildEmpty(10);
        self::assertCount(57, $m->modules);
        self::assertCount(57, $m->modules[0]);
    }
}
