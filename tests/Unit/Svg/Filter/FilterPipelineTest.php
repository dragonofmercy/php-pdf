<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\ColorInterpolation;
use DragonOfMercy\PhpPdf\Svg\Filter\FeDropShadow;
use DragonOfMercy\PhpPdf\Svg\Filter\FeGaussianBlur;
use DragonOfMercy\PhpPdf\Svg\Filter\FeMerge;
use DragonOfMercy\PhpPdf\Svg\Filter\FeOffset;
use DragonOfMercy\PhpPdf\Svg\Filter\FilterPipeline;
use DragonOfMercy\PhpPdf\Svg\Filter\RasterBuffer;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class FilterPipelineTest extends TestCase
{
    public function testEmptyPipelineReturnsSourceGraphic(): void
    {
        $src = new RasterBuffer(2, 2);
        $src->setPixel(0, 0, 1.0, 0.0, 0.0, 1.0);
        $pipe = new FilterPipeline(ColorInterpolation::SRGB, 1.0);
        $out = $pipe->run($src, []);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0], $out->pixel(0, 0), 1e-6);
    }

    public function testOffsetThenMergeUnderSource(): void
    {
        $src = new RasterBuffer(4, 4);
        $src->setPixel(0, 0, 0.0, 0.0, 0.0, 1.0);
        $pipe = new FilterPipeline(ColorInterpolation::SRGB, 1.0);
        $out = $pipe->run($src, [
            new FeOffset(in: 'SourceAlpha', result: 'sh', dx: 1.0, dy: 1.0, subregion: null),
            new FeMerge(result: null, inputs: ['sh', 'SourceGraphic'], subregion: null),
        ]);
        self::assertGreaterThan(0.0, $out->pixel(1, 1)[3]);
        self::assertGreaterThan(0.0, $out->pixel(0, 0)[3]);
    }

    public function testDropShadowWithDefaultInProducesOffsetShadow(): void
    {
        // Single opaque source pixel; drop shadow with default in (SourceGraphic).
        // The shadow is alpha-of(in) offset by (dx, dy), so an alpha appears at
        // the offset location while the original source survives via the merge.
        $src = new RasterBuffer(5, 5);
        $src->setPixel(0, 0, 1.0, 0.0, 0.0, 1.0);
        $pipe = new FilterPipeline(ColorInterpolation::SRGB, 1.0);
        $out = $pipe->run($src, [
            new FeDropShadow(
                in: null,
                result: null,
                dx: 2.0,
                dy: 2.0,
                stdDeviationX: 0.0,
                stdDeviationY: 0.0,
                floodColor: SvgColor::black(),
                floodOpacity: 1.0,
                subregion: null,
            ),
        ]);
        // Source pixel preserved on top.
        self::assertEqualsWithDelta(1.0, $out->pixel(0, 0)[3], 1e-6);
        // Shadow alpha present at the offset location.
        self::assertGreaterThan(0.0, $out->pixel(2, 2)[3]);
    }

    public function testBlurReducesCenterAlpha(): void
    {
        $src = new RasterBuffer(5, 5);
        $src->setPixel(2, 2, 1.0, 1.0, 1.0, 1.0);
        $pipe = new FilterPipeline(ColorInterpolation::LINEAR_RGB, 1.0);
        $out = $pipe->run($src, [new FeGaussianBlur(in: null, result: null, stdDeviationX: 1.0, stdDeviationY: 1.0, subregion: null)]);
        self::assertLessThan(1.0, $out->pixel(2, 2)[3]);
    }
}
