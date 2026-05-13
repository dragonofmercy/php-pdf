<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\ArcToBezier;
use PHPUnit\Framework\TestCase;

final class ArcToBezierTest extends TestCase
{
    public function testQuarterCircleArc(): void
    {
        // Quarter circle: from (1, 0) to (0, 1), rx=ry=1, no rotation, no large-arc, sweep=true.
        // Expect a single cubic with the well-known kappa control points.
        $beziers = ArcToBezier::approximate(1.0, 0.0, 1.0, 1.0, 0.0, false, true, 0.0, 1.0);
        self::assertCount(1, $beziers);
        [$c1x, $c1y, $c2x, $c2y, $ex, $ey] = $beziers[0];
        $kappa = 0.5522847498;
        self::assertEqualsWithDelta(1.0, $c1x, 1e-6);
        self::assertEqualsWithDelta($kappa, $c1y, 1e-6);
        self::assertEqualsWithDelta($kappa, $c2x, 1e-6);
        self::assertEqualsWithDelta(1.0, $c2y, 1e-6);
        self::assertEqualsWithDelta(0.0, $ex, 1e-6);
        self::assertEqualsWithDelta(1.0, $ey, 1e-6);
    }

    public function testZeroRadiusEmitsLine(): void
    {
        // rx=0 -> straight line: a single degenerate cubic at endpoint.
        $beziers = ArcToBezier::approximate(0.0, 0.0, 0.0, 5.0, 0.0, false, false, 10.0, 0.0);
        self::assertCount(1, $beziers);
        [, , , , $ex, $ey] = $beziers[0];
        self::assertSame(10.0, $ex);
        self::assertSame(0.0, $ey);
    }

    public function testLargeArcSplitsIntoMultipleSegments(): void
    {
        // Full half-circle (180deg): splits into 2 cubics of 90deg each.
        $beziers = ArcToBezier::approximate(1.0, 0.0, 1.0, 1.0, 0.0, true, true, -1.0, 0.0);
        self::assertCount(2, $beziers);
        [, , , , $ex2, $ey2] = $beziers[1];
        self::assertEqualsWithDelta(-1.0, $ex2, 1e-6);
        self::assertEqualsWithDelta(0.0, $ey2, 1e-6);
    }

    public function testCounterClockwiseSweep(): void
    {
        $beziers = ArcToBezier::approximate(1.0, 0.0, 1.0, 1.0, 0.0, false, false, 0.0, -1.0);
        self::assertCount(1, $beziers);
        [, , , , $ex, $ey] = $beziers[0];
        self::assertEqualsWithDelta(0.0, $ex, 1e-6);
        self::assertEqualsWithDelta(-1.0, $ey, 1e-6);
    }

    public function testRadiiCorrectionWhenTooSmall(): void
    {
        // Endpoints far apart with tiny radii: algorithm must scale up radii.
        // Just verify a single segment is returned and reaches the endpoint.
        $beziers = ArcToBezier::approximate(0.0, 0.0, 1.0, 1.0, 0.0, false, true, 10.0, 0.0);
        self::assertNotEmpty($beziers);
        $last = $beziers[count($beziers) - 1];
        self::assertEqualsWithDelta(10.0, $last[4], 1e-6);
        self::assertEqualsWithDelta(0.0, $last[5], 1e-6);
    }
}
