<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Marker;

use DragonOfMercy\PhpPdf\Svg\Marker\MarkerKind;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerPositioner;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\SvgLine;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgPolygon;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use PHPUnit\Framework\TestCase;

final class MarkerPositionerTest extends TestCase
{
    private const float EPS = 1e-9;

    public function testLineProducesStartAndEndPositions(): void
    {
        $line = new SvgLine(null, SvgPaint::default(), x1: 0.0, y1: 0.0, x2: 10.0, y2: 0.0);
        $positions = MarkerPositioner::positionsFor($line);
        self::assertCount(2, $positions);
        self::assertSame(MarkerKind::START, $positions[0]->kind);
        self::assertEqualsWithDelta(0.0, $positions[0]->x, self::EPS);
        self::assertEqualsWithDelta(0.0, $positions[0]->angleDeg, self::EPS);
        self::assertSame(MarkerKind::END, $positions[1]->kind);
        self::assertEqualsWithDelta(10.0, $positions[1]->x, self::EPS);
        self::assertEqualsWithDelta(0.0, $positions[1]->angleDeg, self::EPS);
    }

    public function testVerticalLineTangent90(): void
    {
        $line = new SvgLine(null, SvgPaint::default(), x1: 0.0, y1: 0.0, x2: 0.0, y2: 10.0);
        $positions = MarkerPositioner::positionsFor($line);
        self::assertEqualsWithDelta(90.0, $positions[0]->angleDeg, self::EPS);
        self::assertEqualsWithDelta(90.0, $positions[1]->angleDeg, self::EPS);
    }

    public function testPolylineMidUsesBisector(): void
    {
        $poly = new SvgPolyline(null, SvgPaint::default(), points: [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]]);
        $positions = MarkerPositioner::positionsFor($poly);
        self::assertCount(3, $positions);
        self::assertSame(MarkerKind::START, $positions[0]->kind);
        self::assertSame(MarkerKind::MID, $positions[1]->kind);
        self::assertSame(MarkerKind::END, $positions[2]->kind);
        self::assertEqualsWithDelta(45.0, $positions[1]->angleDeg, self::EPS);
    }

    public function testPolygonWrapsAround(): void
    {
        $poly = new SvgPolygon(null, SvgPaint::default(), points: [[0.0, 0.0], [10.0, 0.0], [5.0, 10.0]]);
        $positions = MarkerPositioner::positionsFor($poly);
        self::assertCount(3, $positions);
        self::assertSame(MarkerKind::START, $positions[0]->kind);
        self::assertSame(MarkerKind::MID, $positions[1]->kind);
        self::assertSame(MarkerKind::END, $positions[2]->kind);
    }

    public function testPathLineToTangent(): void
    {
        $path = new SvgPath(null, SvgPaint::default(), commands: [
            new MoveTo(0.0, 0.0),
            new LineTo(10.0, 0.0),
        ]);
        $positions = MarkerPositioner::positionsFor($path);
        self::assertCount(2, $positions);
        self::assertEqualsWithDelta(0.0, $positions[1]->angleDeg, self::EPS);
    }

    public function testPathCubicEndpointTangent(): void
    {
        $path = new SvgPath(null, SvgPaint::default(), commands: [
            new MoveTo(0.0, 0.0),
            new CubicBezier(c1x: 5.0, c1y: 0.0, c2x: 5.0, c2y: 10.0, x: 10.0, y: 10.0),
        ]);
        $positions = MarkerPositioner::positionsFor($path);
        self::assertEqualsWithDelta(0.0, $positions[1]->angleDeg, self::EPS);
    }

    public function testPathArcEmitsOnlyOneKnotAtEndpoint(): void
    {
        // A 90-degree arc from (10,0) to (0,10) with r=10 produces 1 bezier segment;
        // a 270-degree arc would produce 3 segments and previously emitted 3 knots (1 END + 2 spurious MIDs).
        // After the fix, both arcs emit exactly 1 endpoint knot.
        $path = new SvgPath(null, SvgPaint::default(), commands: [
            new MoveTo(10.0, 0.0),
            new Arc(rx: 10.0, ry: 10.0, xAxisRotationDeg: 0.0, largeArc: true, sweep: false, x: 0.0, y: 10.0),
        ]);
        $positions = MarkerPositioner::positionsFor($path);
        // Expect 2 positions total: START at MoveTo, END at arc endpoint. No MIDs.
        self::assertCount(2, $positions);
        self::assertSame(MarkerKind::START, $positions[0]->kind);
        self::assertSame(MarkerKind::END, $positions[1]->kind);
        // Endpoint coords.
        self::assertEqualsWithDelta(0.0, $positions[1]->x, 1e-9);
        self::assertEqualsWithDelta(10.0, $positions[1]->y, 1e-9);
    }
}
