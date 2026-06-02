<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\PolygonFiller;
use DragonOfMercy\PhpPdf\Svg\Filter\RasterBuffer;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use PHPUnit\Framework\TestCase;

final class PolygonFillerTest extends TestCase
{
    public function testFillsInteriorOpaque(): void
    {
        $buf = new RasterBuffer(10, 10);
        $square = [[['x' => 2.0, 'y' => 2.0], ['x' => 8.0, 'y' => 2.0], ['x' => 8.0, 'y' => 8.0], ['x' => 2.0, 'y' => 8.0]]];
        PolygonFiller::fill($buf, $square, FillRule::NONZERO, 1.0, 0.0, 0.0, 1.0);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0], $buf->pixel(5, 5), 1e-9);
        self::assertSame([0.0, 0.0, 0.0, 0.0], $buf->pixel(0, 0));
    }

    public function testEvenOddLeavesHole(): void
    {
        $buf = new RasterBuffer(20, 20);
        $outer = [['x' => 2.0, 'y' => 2.0], ['x' => 18.0, 'y' => 2.0], ['x' => 18.0, 'y' => 18.0], ['x' => 2.0, 'y' => 18.0]];
        $inner = [['x' => 7.0, 'y' => 7.0], ['x' => 13.0, 'y' => 7.0], ['x' => 13.0, 'y' => 13.0], ['x' => 7.0, 'y' => 13.0]];
        PolygonFiller::fill($buf, [$outer, $inner], FillRule::EVENODD, 0.0, 0.0, 0.0, 1.0);
        self::assertSame(0.0, $buf->pixel(10, 10)[3]);
        self::assertEqualsWithDelta(1.0, $buf->pixel(4, 10)[3], 1e-9);
    }
}
