<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\RadialGradient;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgGradient;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgPaintSource;
use PHPUnit\Framework\TestCase;

final class GradientValueObjectTest extends TestCase
{
    public function testSvgColorIsAPaintSource(): void
    {
        self::assertInstanceOf(SvgPaintSource::class, SvgColor::black());
    }

    public function testLinearGradientExposesAccessors(): void
    {
        $stops = [new GradientStop(0.0, SvgColor::black(), 1.0), new GradientStop(1.0, SvgColor::fromBytes(255, 0, 0), 1.0)];
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $stops, 1.0);
        self::assertInstanceOf(SvgGradient::class, $g);
        self::assertInstanceOf(SvgPaintSource::class, $g);
        self::assertSame(GradientUnits::OBJECT_BOUNDING_BOX, $g->units());
        self::assertNull($g->transform());
        self::assertCount(2, $g->stops());
        self::assertSame(1.0, $g->uniformOpacity());
        self::assertSame(1.0, $g->x2);
    }

    public function testRadialGradientExposesAccessors(): void
    {
        $stops = [new GradientStop(0.0, SvgColor::black(), 0.5), new GradientStop(1.0, SvgColor::black(), 0.5)];
        $g = new RadialGradient(0.5, 0.5, 0.5, 0.5, 0.5, GradientUnits::USER_SPACE_ON_USE, SvgMatrix::scale(2.0), $stops, 0.5);
        self::assertSame(GradientUnits::USER_SPACE_ON_USE, $g->units());
        self::assertNotNull($g->transform());
        self::assertSame(0.5, $g->uniformOpacity());
        self::assertSame(0.5, $g->r);
    }

    public function testStopCarriesOffsetColorOpacity(): void
    {
        $stop = new GradientStop(0.25, SvgColor::fromBytes(10, 20, 30), 0.75);
        self::assertSame(0.25, $stop->offset);
        self::assertSame(0.75, $stop->opacity);
        self::assertEqualsWithDelta(10.0 / 255.0, $stop->color->r, 1e-9);
    }
}
