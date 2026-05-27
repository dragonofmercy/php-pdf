<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\BoundingBox;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
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
}
