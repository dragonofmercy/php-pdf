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

    public function testV20HasSize97(): void
    {
        $m = Matrix::buildEmpty(20);
        self::assertCount(97, $m->modules);
        self::assertCount(97, $m->modules[0]);
    }

    public function testV40HasSize177(): void
    {
        $m = Matrix::buildEmpty(40);
        self::assertCount(177, $m->modules);
        self::assertCount(177, $m->modules[0]);
    }

    public function testV20AlignmentPatternAt62x62IsPlaced(): void
    {
        // V20 alignment positions per ISO 18004 Annex E: [6, 34, 62, 90].
        // The center at (62, 62) is in the data area (not on a finder), so it
        // should have been written and reserved by buildEmpty.
        $m = Matrix::buildEmpty(20);
        self::assertTrue($m->modules[62][62], 'V20 alignment center (62,62) should be dark');
        self::assertTrue($m->reserved[62][62], 'V20 alignment center (62,62) should be reserved');
        // Outer ring (radius 2) of the alignment pattern is also dark.
        self::assertTrue($m->modules[60][62], 'V20 alignment ring (60,62) should be dark');
        self::assertTrue($m->modules[64][62], 'V20 alignment ring (64,62) should be dark');
        // The light ring (radius 1) inside the alignment pattern.
        self::assertFalse($m->modules[61][62], 'V20 alignment light ring (61,62) should be light');
    }

    public function testV40AlignmentPatternAt170x170IsPlaced(): void
    {
        // V40 alignment positions per ISO 18004 Annex E: [6, 30, 58, 86, 114, 142, 170].
        // The last position 170 == size - 7 == 177 - 7. Center (170, 170) is in the
        // bottom-right data area.
        $m = Matrix::buildEmpty(40);
        self::assertTrue($m->modules[170][170], 'V40 alignment center (170,170) should be dark');
        self::assertTrue($m->reserved[170][170], 'V40 alignment center (170,170) should be reserved');
    }
}
