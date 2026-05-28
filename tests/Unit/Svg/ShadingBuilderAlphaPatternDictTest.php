<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\RadialGradient;
use DragonOfMercy\PhpPdf\Svg\ShadingBuilder;
use DragonOfMercy\PhpPdf\Svg\SpreadMethod;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use PHPUnit\Framework\TestCase;

final class ShadingBuilderAlphaPatternDictTest extends TestCase
{
    public function testLinearAlphaDictHasDeviceGrayAndAxialShading(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(1.0, SvgColor::black(), 0.0),
        ];
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $stops, 1.0, SpreadMethod::PAD);
        $dict = ShadingBuilder::alphaPatternDict($g, SvgMatrix::identity());
        self::assertStringContainsString('/Type /Pattern', $dict);
        self::assertStringContainsString('/PatternType 2', $dict);
        self::assertStringContainsString('/ShadingType 2', $dict);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $dict);
        // PDF SMask semantics: gray 1 = fully opaque, gray 0 = transparent.
        // So stop opacity=1 -> C0=[1], opacity=0 -> C1=[0].
        self::assertStringContainsString('/C0 [1]', $dict);
        self::assertStringContainsString('/C1 [0]', $dict);
    }

    public function testRadialAlphaDictHasShadingType3(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(1.0, SvgColor::black(), 0.5),
        ];
        $g = new RadialGradient(0.5, 0.5, 0.5, 0.5, 0.5, GradientUnits::OBJECT_BOUNDING_BOX, null, $stops, 1.0, SpreadMethod::PAD);
        $dict = ShadingBuilder::alphaPatternDict($g, SvgMatrix::identity());
        self::assertStringContainsString('/ShadingType 3', $dict);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $dict);
    }

    public function testThreeStopAlphaUsesStitchingFunction(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(0.5, SvgColor::black(), 0.5),
            new GradientStop(1.0, SvgColor::black(), 0.0),
        ];
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $stops, 1.0, SpreadMethod::PAD);
        $dict = ShadingBuilder::alphaPatternDict($g, SvgMatrix::identity());
        self::assertStringContainsString('/FunctionType 3', $dict);
        self::assertStringContainsString('/Bounds [0.5]', $dict);
    }

    public function testMatrixIsIncluded(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(1.0, SvgColor::black(), 0.0),
        ];
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $stops, 1.0, SpreadMethod::PAD);
        $m = SvgMatrix::translate(10.0, 20.0)->compose(SvgMatrix::scale(100.0, 200.0));
        $dict = ShadingBuilder::alphaPatternDict($g, $m);
        self::assertStringContainsString('/Matrix [', $dict);
        self::assertStringContainsString('100', $dict);
    }
}
