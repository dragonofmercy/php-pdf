<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\PatternUnits;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgPaintSource;
use DragonOfMercy\PhpPdf\Svg\SvgPattern;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use PHPUnit\Framework\TestCase;

final class SvgPatternTest extends TestCase
{
    public function testIsAPaintSource(): void
    {
        $p = new SvgPattern(
            units: PatternUnits::OBJECT_BOUNDING_BOX,
            x: 0.0, y: 0.0, width: 0.25, height: 0.25,
            transform: null,
            viewBox: null,
            nodes: [],
        );
        self::assertInstanceOf(SvgPaintSource::class, $p);
    }

    public function testCarriesAllAttributes(): void
    {
        // SvgCircle ctor: (?SvgMatrix $transform, SvgPaint $paint, float $cx, float $cy, float $r)
        $node = new SvgCircle(transform: null, paint: SvgPaint::default(), cx: 5.0, cy: 5.0, r: 2.0);
        $vb = new ViewBox(x: 0.0, y: 0.0, width: 10.0, height: 10.0);
        $matrix = SvgMatrix::rotate(45.0);
        $p = new SvgPattern(
            units: PatternUnits::USER_SPACE_ON_USE,
            x: 1.0, y: 2.0, width: 3.0, height: 4.0,
            transform: $matrix,
            viewBox: $vb,
            nodes: [$node],
        );
        self::assertSame(PatternUnits::USER_SPACE_ON_USE, $p->units);
        self::assertSame(1.0, $p->x);
        self::assertSame(2.0, $p->y);
        self::assertSame(3.0, $p->width);
        self::assertSame(4.0, $p->height);
        self::assertSame($matrix, $p->transform);
        self::assertSame($vb, $p->viewBox);
        self::assertCount(1, $p->nodes);
    }
}
