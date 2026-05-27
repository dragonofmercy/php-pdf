<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\BoundingBox;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgEllipse;
use DragonOfMercy\PhpPdf\Svg\SvgLine;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgPolygon;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class BoundingBoxTest extends TestCase
{
    public function testRect(): void
    {
        $bb = BoundingBox::of(new SvgRect(null, SvgPaint::default(), 2.0, 3.0, 4.0, 5.0, 0.0, 0.0));
        self::assertSame([2.0, 3.0, 4.0, 5.0], [$bb->x, $bb->y, $bb->width, $bb->height]);
    }

    public function testCircle(): void
    {
        $bb = BoundingBox::of(new SvgCircle(null, SvgPaint::default(), 10.0, 10.0, 4.0));
        self::assertSame([6.0, 6.0, 8.0, 8.0], [$bb->x, $bb->y, $bb->width, $bb->height]);
    }

    public function testCubicExtremaTight(): void
    {
        $path = new SvgPath(null, SvgPaint::default(), [
            new MoveTo(0.0, 0.0),
            new CubicBezier(0.0, 100.0, 100.0, 100.0, 100.0, 0.0),
        ]);
        $bb = BoundingBox::of($path);
        self::assertSame(0.0, $bb->x);
        self::assertSame(0.0, $bb->y);
        self::assertSame(100.0, $bb->width);
        self::assertEqualsWithDelta(75.0, $bb->height, 1e-6);
    }

    public function testZeroAreaDetected(): void
    {
        $bb = BoundingBox::of(new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 0.0, 0.0, 0.0));
        self::assertTrue($bb->isDegenerate());
    }

    public function testEllipse(): void
    {
        $bb = BoundingBox::of(new SvgEllipse(null, SvgPaint::default(), 20.0, 30.0, 5.0, 8.0));
        self::assertSame([15.0, 22.0, 10.0, 16.0], [$bb->x, $bb->y, $bb->width, $bb->height]);
    }

    public function testLine(): void
    {
        $bb = BoundingBox::of(new SvgLine(null, SvgPaint::default(), 3.0, 9.0, 1.0, 2.0));
        self::assertSame([1.0, 2.0, 2.0, 7.0], [$bb->x, $bb->y, $bb->width, $bb->height]);
    }

    public function testPolygon(): void
    {
        $bb = BoundingBox::of(new SvgPolygon(null, SvgPaint::default(), [[0.0, 0.0], [10.0, 4.0], [2.0, 9.0]]));
        self::assertSame([0.0, 0.0, 10.0, 9.0], [$bb->x, $bb->y, $bb->width, $bb->height]);
    }

    public function testPolyline(): void
    {
        $bb = BoundingBox::of(new SvgPolyline(null, SvgPaint::default(), [[0.0, 0.0], [10.0, 4.0], [2.0, 9.0]]));
        self::assertSame([0.0, 0.0, 10.0, 9.0], [$bb->x, $bb->y, $bb->width, $bb->height]);
    }

    public function testQuadraticExtremaTight(): void
    {
        // MoveTo(0,0) then QuadraticBezier(cx=50,cy=100,x=100,y=0).
        // Peak at t=0.5: y = 50 (degree-elevated to cubic internally).
        $path = new SvgPath(null, SvgPaint::default(), [
            new MoveTo(0.0, 0.0),
            new QuadraticBezier(50.0, 100.0, 100.0, 0.0),
        ]);
        $bb = BoundingBox::of($path);
        self::assertSame(0.0, $bb->x);
        self::assertSame(0.0, $bb->y);
        self::assertSame(100.0, $bb->width);
        self::assertEqualsWithDelta(50.0, $bb->height, 1e-6);
    }

    public function testEmptyPathIsZeroBox(): void
    {
        $bb = BoundingBox::of(new SvgPath(null, SvgPaint::default(), []));
        self::assertTrue($bb->isDegenerate());
        self::assertSame(0.0, $bb->x);
        self::assertSame(0.0, $bb->y);
        self::assertSame(0.0, $bb->width);
        self::assertSame(0.0, $bb->height);
    }
}
