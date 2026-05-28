<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\GradientResolver;
use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class GradientResolverHasVaryingAlphaTest extends TestCase
{
    public function testEmptyStopsIsFalse(): void
    {
        self::assertFalse(GradientResolver::hasVaryingAlpha([]));
    }

    public function testAllOpaqueIsFalse(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(0.5, SvgColor::black(), 1.0),
            new GradientStop(1.0, SvgColor::black(), 1.0),
        ];
        self::assertFalse(GradientResolver::hasVaryingAlpha($stops));
    }

    public function testAllSameNonOpaqueIsFalse(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 0.5),
            new GradientStop(1.0, SvgColor::black(), 0.5),
        ];
        self::assertFalse(GradientResolver::hasVaryingAlpha($stops));
    }

    public function testOneDifferentIsTrue(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(1.0, SvgColor::black(), 0.0),
        ];
        self::assertTrue(GradientResolver::hasVaryingAlpha($stops));
    }

    public function testThreeStopsOneVariesIsTrue(): void
    {
        $stops = [
            new GradientStop(0.0, SvgColor::black(), 1.0),
            new GradientStop(0.5, SvgColor::black(), 0.5),
            new GradientStop(1.0, SvgColor::black(), 1.0),
        ];
        self::assertTrue(GradientResolver::hasVaryingAlpha($stops));
    }

    public function testSingleStopIsFalse(): void
    {
        $stops = [new GradientStop(0.5, SvgColor::black(), 0.3)];
        self::assertFalse(GradientResolver::hasVaryingAlpha($stops));
    }
}
