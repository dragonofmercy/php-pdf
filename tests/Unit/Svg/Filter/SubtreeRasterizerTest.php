<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\SubtreeRasterizer;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use PHPUnit\Framework\TestCase;

final class SubtreeRasterizerTest extends TestCase
{
    public function testFillsSolidRectIntoBuffer(): void
    {
        $paint = SvgPaint::default()->withFill(new SvgColor(1.0, 0.0, 0.0));
        $rect = new SvgRect(null, $paint, 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $group = new SvgGroup(null, [$rect]);

        $buf = (new SubtreeRasterizer())->rasterize($group, SvgMatrix::identity(), 10, 10);

        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0], $buf->pixel(5, 5), 1e-6);
    }

    public function testTextIsSkipped(): void
    {
        $text = new SvgText(null, []);
        $group = new SvgGroup(null, [$text]);

        $buf = (new SubtreeRasterizer())->rasterize($group, SvgMatrix::identity(), 10, 10);

        self::assertSame([0.0, 0.0, 0.0, 0.0], $buf->pixel(5, 5));
    }
}
