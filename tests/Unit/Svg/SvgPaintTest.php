<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\StrokeLineCap;
use DragonOfMercy\PhpPdf\Svg\StrokeLineJoin;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use PHPUnit\Framework\TestCase;

final class SvgPaintTest extends TestCase
{
    public function testDefaultsMatchSpec(): void
    {
        $p = SvgPaint::default();
        self::assertNotNull($p->fill);
        self::assertEquals(SvgColor::black(), $p->fill);
        self::assertNull($p->stroke);
        self::assertSame(1.0, $p->strokeWidth);
        self::assertSame(StrokeLineCap::BUTT, $p->strokeLineCap);
        self::assertSame(StrokeLineJoin::MITER, $p->strokeLineJoin);
        self::assertSame(4.0, $p->strokeMiterLimit);
        self::assertSame([], $p->strokeDashArray);
        self::assertSame(0.0, $p->strokeDashOffset);
        self::assertSame(FillRule::NONZERO, $p->fillRule);
        self::assertSame(1.0, $p->fillOpacity);
        self::assertSame(1.0, $p->strokeOpacity);
        self::assertSame(1.0, $p->opacity);
    }

    public function testEffectiveFillOpacityCombinesOpacityAndFillOpacity(): void
    {
        $p = SvgPaint::default()
            ->withOpacity(0.5)
            ->withFillOpacity(0.4);
        self::assertEqualsWithDelta(0.5 * 0.4, $p->effectiveFillOpacity(), 1e-9);
    }

    public function testEffectiveStrokeOpacityCombinesOpacityAndStrokeOpacity(): void
    {
        $p = SvgPaint::default()
            ->withOpacity(0.5)
            ->withStrokeOpacity(0.5);
        self::assertEqualsWithDelta(0.25, $p->effectiveStrokeOpacity(), 1e-9);
    }

    public function testWithersAreImmutable(): void
    {
        $base = SvgPaint::default();
        $modified = $base->withFill(new SvgColor(1.0, 0.0, 0.0));
        self::assertNotNull($base->fill);
        self::assertEquals(SvgColor::black(), $base->fill);
        self::assertNotNull($modified->fill);
        self::assertEquals(new SvgColor(1.0, 0.0, 0.0), $modified->fill);
    }

    public function testWithFillNoneSetsNull(): void
    {
        $p = SvgPaint::default()->withFillNone();
        self::assertNull($p->fill);
    }

    public function testWithStrokeAssigns(): void
    {
        $p = SvgPaint::default()->withStroke(new SvgColor(0.0, 0.0, 1.0));
        self::assertNotNull($p->stroke);
    }
}
