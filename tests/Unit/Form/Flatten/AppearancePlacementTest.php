<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Flatten;

use DragonOfMercy\PhpPdf\Form\Flatten\AppearancePlacement;
use PHPUnit\Framework\TestCase;

final class AppearancePlacementTest extends TestCase
{
    public function testIdentityBoxMatchingRectIsPureTranslation(): void
    {
        $cm = AppearancePlacement::matrix([0.0, 0.0, 80.0, 8.0], [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], [100.0, 200.0, 180.0, 208.0]);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 100.0, 200.0], $cm, 1e-9);
    }

    public function testScalesWhenRectLargerThanBox(): void
    {
        $cm = AppearancePlacement::matrix([0.0, 0.0, 10.0, 10.0], [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], [0.0, 0.0, 20.0, 40.0]);
        self::assertEqualsWithDelta([2.0, 0.0, 0.0, 4.0, 0.0, 0.0], $cm, 1e-9);
    }

    public function testNonIdentityMatrixEnclosingBoxIsUsed(): void
    {
        // BBox [0 0 10 10] rotated 90 degrees: matrix [0 1 -1 0 0 0].
        // Transformed corners span x in [-10,0], y in [0,10] -> box 10x10 at (-10,0).
        // Rect [0 0 10 10] -> sx=1, sy=1, e = 0 - 1*(-10) = 10, f = 0 - 1*0 = 0.
        $cm = AppearancePlacement::matrix([0.0, 0.0, 10.0, 10.0], [0.0, 1.0, -1.0, 0.0, 0.0, 0.0], [0.0, 0.0, 10.0, 10.0]);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 10.0, 0.0], $cm, 1e-9);
    }

    public function testDegenerateBoxFallsBackToUnitScale(): void
    {
        $cm = AppearancePlacement::matrix([0.0, 0.0, 0.0, 10.0], [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], [5.0, 5.0, 5.0, 15.0]);
        self::assertSame(1.0, $cm[0]);
        self::assertSame(1.0, $cm[3]);
    }
}
