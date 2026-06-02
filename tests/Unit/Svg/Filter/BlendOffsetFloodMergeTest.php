<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\Blend;
use DragonOfMercy\PhpPdf\Svg\Filter\BlendMode;
use DragonOfMercy\PhpPdf\Svg\Filter\Flood;
use DragonOfMercy\PhpPdf\Svg\Filter\Merge;
use DragonOfMercy\PhpPdf\Svg\Filter\Offset;
use DragonOfMercy\PhpPdf\Svg\Filter\RasterBuffer;
use PHPUnit\Framework\TestCase;

final class BlendOffsetFloodMergeTest extends TestCase
{
    public function testOffsetShiftsPixels(): void
    {
        $buf = new RasterBuffer(3, 3);
        $buf->setPixel(0, 0, 1.0, 1.0, 1.0, 1.0);
        $out = Offset::apply($buf, 1, 1);
        self::assertSame([0.0, 0.0, 0.0, 0.0], $out->pixel(0, 0));
        self::assertSame([1.0, 1.0, 1.0, 1.0], $out->pixel(1, 1));
    }

    public function testFloodFillsRegion(): void
    {
        $out = Flood::apply(2, 2, 1.0, 0.0, 0.0, 0.5);
        self::assertSame([1.0, 0.0, 0.0, 0.5], $out->pixel(1, 1));
    }

    public function testMergeStacksSourceOver(): void
    {
        $bottom = new RasterBuffer(1, 1);
        $bottom->setPixel(0, 0, 0.0, 0.0, 1.0, 1.0);
        $top = new RasterBuffer(1, 1);
        $top->setPixel(0, 0, 1.0, 0.0, 0.0, 0.5);
        $out = Merge::apply([$bottom, $top]);
        $p = $out->pixel(0, 0);
        self::assertEqualsWithDelta(0.5, $p[0], 1e-6);
        self::assertEqualsWithDelta(0.5, $p[2], 1e-6);
        self::assertEqualsWithDelta(1.0, $p[3], 1e-6);
    }

    public function testBlendMultiply(): void
    {
        $a = new RasterBuffer(1, 1);
        $a->setPixel(0, 0, 0.5, 0.5, 0.5, 1.0);
        $b = new RasterBuffer(1, 1);
        $b->setPixel(0, 0, 0.5, 0.5, 0.5, 1.0);
        $out = Blend::apply($a, $b, BlendMode::MULTIPLY);
        self::assertEqualsWithDelta(0.25, $out->pixel(0, 0)[0], 1e-6);
    }

    public function testMergeEmptyThrows(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        Merge::apply([]);
    }
}
