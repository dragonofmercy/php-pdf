<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use PHPUnit\Framework\TestCase;

final class SvgPaintSourceTest extends TestCase
{
    public function testWithFillAcceptsGradient(): void
    {
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, [new GradientStop(0.0, SvgColor::black(), 1.0)], 1.0);
        $paint = SvgPaint::default()->withFill($g);
        self::assertSame($g, $paint->fill);
    }

    public function testWithFillStillAcceptsColor(): void
    {
        $c = SvgColor::fromBytes(1, 2, 3);
        $paint = SvgPaint::default()->withStroke($c);
        self::assertSame($c, $paint->stroke);
    }
}
