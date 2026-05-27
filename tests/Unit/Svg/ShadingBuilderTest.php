<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\RadialGradient;
use DragonOfMercy\PhpPdf\Svg\ShadingBuilder;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use PHPUnit\Framework\TestCase;

final class ShadingBuilderTest extends TestCase
{
    public function testLinearTwoStopsFunctionType2(): void
    {
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::USER_SPACE_ON_USE, null, [
            new GradientStop(0.0, SvgColor::fromBytes(255, 0, 0), 1.0),
            new GradientStop(1.0, SvgColor::fromBytes(0, 0, 255), 1.0),
        ], 1.0);
        $dict = ShadingBuilder::patternDict($g, SvgMatrix::identity());
        self::assertStringContainsString('/PatternType 2', $dict);
        self::assertStringContainsString('/Matrix [1 0 0 1 0 0]', $dict);
        self::assertStringContainsString('/ShadingType 2', $dict);
        self::assertStringContainsString('/Coords [0 0 1 0]', $dict);
        self::assertStringContainsString('/FunctionType 2', $dict);
        self::assertStringContainsString('/C0 [1 0 0]', $dict);
        self::assertStringContainsString('/C1 [0 0 1]', $dict);
        self::assertStringContainsString('/Extend [true true]', $dict);
    }

    public function testLinearMultiStopFunctionType3(): void
    {
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::USER_SPACE_ON_USE, null, [
            new GradientStop(0.0, SvgColor::fromBytes(255, 0, 0), 1.0),
            new GradientStop(0.5, SvgColor::fromBytes(0, 255, 0), 1.0),
            new GradientStop(1.0, SvgColor::fromBytes(0, 0, 255), 1.0),
        ], 1.0);
        $dict = ShadingBuilder::patternDict($g, SvgMatrix::identity());
        self::assertStringContainsString('/FunctionType 3', $dict);
        self::assertStringContainsString('/Bounds [0.5]', $dict);
        self::assertStringContainsString('/Encode [0 1 0 1]', $dict);
    }

    public function testRadialCoords(): void
    {
        $g = new RadialGradient(0.5, 0.5, 0.5, 0.2, 0.3, GradientUnits::OBJECT_BOUNDING_BOX, null, [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(1.0, SvgColor::fromBytes(255, 255, 255), 1.0),
        ], 1.0);
        $dict = ShadingBuilder::patternDict($g, SvgMatrix::scale(10.0));
        self::assertStringContainsString('/ShadingType 3', $dict);
        self::assertStringContainsString('/Coords [0.2 0.3 0 0.5 0.5 0.5]', $dict);
        self::assertStringContainsString('/Matrix [10 0 0 10 0 0]', $dict);
    }
}
