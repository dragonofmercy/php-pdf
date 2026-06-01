<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\TextPath;

use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\TextPath\PathPolyline;
use PHPUnit\Framework\TestCase;

final class PathPolylineTest extends TestCase
{
    public function testLengthOfStraightHorizontalLine(): void
    {
        $poly = PathPolyline::fromCommands([new MoveTo(0.0, 0.0), new LineTo(100.0, 0.0)]);
        self::assertEqualsWithDelta(100.0, $poly->length(), 1e-6);
    }

    public function testLengthOfLShape(): void
    {
        $poly = PathPolyline::fromCommands([
            new MoveTo(0.0, 0.0),
            new LineTo(30.0, 0.0),
            new LineTo(30.0, 40.0),
        ]);
        self::assertEqualsWithDelta(70.0, $poly->length(), 1e-6);
    }

    public function testPointAtMidpointOfHorizontalSegmentHasZeroAngle(): void
    {
        $poly = PathPolyline::fromCommands([new MoveTo(0.0, 0.0), new LineTo(100.0, 0.0)]);
        $p = $poly->pointAt(50.0);
        self::assertEqualsWithDelta(50.0, $p['x'], 1e-6);
        self::assertEqualsWithDelta(0.0, $p['y'], 1e-6);
        self::assertEqualsWithDelta(0.0, $p['angleDeg'], 1e-6);
    }

    public function testPointAtOnVerticalSegmentHasNinetyDegrees(): void
    {
        $poly = PathPolyline::fromCommands([new MoveTo(0.0, 0.0), new LineTo(0.0, 100.0)]);
        $p = $poly->pointAt(40.0);
        self::assertEqualsWithDelta(0.0, $p['x'], 1e-6);
        self::assertEqualsWithDelta(40.0, $p['y'], 1e-6);
        self::assertEqualsWithDelta(90.0, $p['angleDeg'], 1e-6);
    }

    public function testPointAtClampsBeyondEnds(): void
    {
        $poly = PathPolyline::fromCommands([new MoveTo(0.0, 0.0), new LineTo(10.0, 0.0)]);
        $past = $poly->pointAt(999.0);
        self::assertEqualsWithDelta(10.0, $past['x'], 1e-6);
        $before = $poly->pointAt(-5.0);
        self::assertEqualsWithDelta(0.0, $before['x'], 1e-6);
    }

    public function testCubicBezierLengthIsApproximatelyChordToArc(): void
    {
        $poly = PathPolyline::fromCommands([
            new MoveTo(0.0, 0.0),
            new \DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier(0.0, 50.0, 100.0, 50.0, 100.0, 0.0),
        ]);
        self::assertGreaterThan(100.0, $poly->length());
        self::assertLessThan(250.0, $poly->length());
    }
}
