<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgEllipse;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgLine;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgPathCommand;
use DragonOfMercy\PhpPdf\Svg\SvgPolygon;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\SvgShape;
use PHPUnit\Framework\TestCase;

final class AstTest extends TestCase
{
    public function testEmptyGroup(): void
    {
        $g = new SvgGroup(null, []);
        self::assertInstanceOf(SvgNode::class, $g);
        self::assertNull($g->transform);
        self::assertSame([], $g->children);
    }

    public function testNestedGroup(): void
    {
        $inner = new SvgGroup(SvgMatrix::translate(10.0, 0.0), []);
        $outer = new SvgGroup(null, [$inner]);
        self::assertCount(1, $outer->children);
        self::assertSame($inner, $outer->children[0]);
    }

    public function testPathShapeImplementsBothInterfaces(): void
    {
        $shape = new SvgPath(null, SvgPaint::default(), [new MoveTo(0.0, 0.0), new LineTo(10.0, 10.0), new ClosePath()]);
        self::assertInstanceOf(SvgNode::class, $shape);
        self::assertInstanceOf(SvgShape::class, $shape);
        self::assertNull($shape->transform());
        self::assertNotNull($shape->paint()->fill);
        self::assertCount(3, $shape->commands);
    }

    public function testRectStoresGeometry(): void
    {
        $r = new SvgRect(null, SvgPaint::default(), 1.0, 2.0, 3.0, 4.0, 0.5, 0.5);
        self::assertSame(1.0, $r->x);
        self::assertSame(2.0, $r->y);
        self::assertSame(3.0, $r->width);
        self::assertSame(4.0, $r->height);
        self::assertTrue($r->hasRoundedCorners());
    }

    public function testRectWithoutRoundedCorners(): void
    {
        $r = new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        self::assertFalse($r->hasRoundedCorners());
    }

    public function testCircleStoresGeometry(): void
    {
        $c = new SvgCircle(null, SvgPaint::default(), 12.0, 12.0, 6.0);
        self::assertSame(12.0, $c->cx);
        self::assertSame(6.0, $c->r);
    }

    public function testEllipseStoresGeometry(): void
    {
        $e = new SvgEllipse(null, SvgPaint::default(), 0.0, 0.0, 5.0, 3.0);
        self::assertSame(5.0, $e->rx);
        self::assertSame(3.0, $e->ry);
    }

    public function testLineStoresEndpoints(): void
    {
        $l = new SvgLine(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0);
        self::assertSame(10.0, $l->x2);
    }

    public function testPolygonStoresPoints(): void
    {
        $p = new SvgPolygon(null, SvgPaint::default(), [[0.0, 0.0], [10.0, 0.0], [5.0, 10.0]]);
        self::assertCount(3, $p->points);
        self::assertSame([10.0, 0.0], $p->points[1]);
    }

    public function testPolylineStoresPoints(): void
    {
        $p = new SvgPolyline(null, SvgPaint::default(), [[0.0, 0.0], [5.0, 10.0]]);
        self::assertCount(2, $p->points);
    }

    public function testPathCommandsAreAllSvgPathCommand(): void
    {
        $commands = [
            new MoveTo(0.0, 0.0),
            new LineTo(1.0, 1.0),
            new CubicBezier(0.0, 0.0, 1.0, 0.0, 1.0, 1.0),
            new QuadraticBezier(0.5, 0.0, 1.0, 1.0),
            new Arc(5.0, 5.0, 0.0, false, true, 10.0, 10.0),
            new ClosePath(),
        ];
        foreach ($commands as $cmd) {
            self::assertInstanceOf(SvgPathCommand::class, $cmd);
        }
    }

    public function testArcCarriesFlags(): void
    {
        $a = new Arc(5.0, 5.0, 0.0, true, false, 10.0, 10.0);
        self::assertTrue($a->largeArc);
        self::assertFalse($a->sweep);
    }
}
