<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\StrokeLineCap;
use DragonOfMercy\PhpPdf\Svg\StyleResolver;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use PHPUnit\Framework\TestCase;

final class StyleResolverTest extends TestCase
{
    public function testNoChangesReturnsInherited(): void
    {
        $base = SvgPaint::default();
        $out = StyleResolver::resolve($base, [], '', SvgColor::black());
        self::assertEquals($base, $out);
    }

    public function testPresentationAttrFillRed(): void
    {
        $out = StyleResolver::resolve(SvgPaint::default(), ['fill' => 'red'], '', SvgColor::black());
        self::assertNotNull($out->fill);
        self::assertEquals(SvgColor::fromBytes(255, 0, 0), $out->fill);
    }

    public function testPresentationAttrFillNone(): void
    {
        $out = StyleResolver::resolve(SvgPaint::default(), ['fill' => 'none'], '', SvgColor::black());
        self::assertNull($out->fill);
    }

    public function testInlineStyleOverridesAttribute(): void
    {
        $out = StyleResolver::resolve(
            SvgPaint::default(),
            ['fill' => 'red'],
            'fill: blue',
            SvgColor::black(),
        );
        self::assertNotNull($out->fill);
        self::assertEquals(SvgColor::fromBytes(0, 0, 255), $out->fill);
    }

    public function testStrokeWidth(): void
    {
        $out = StyleResolver::resolve(SvgPaint::default(), ['stroke-width' => '2.5'], '', SvgColor::black());
        self::assertSame(2.5, $out->strokeWidth);
    }

    public function testStrokeLineCap(): void
    {
        $out = StyleResolver::resolve(SvgPaint::default(), ['stroke-linecap' => 'round'], '', SvgColor::black());
        self::assertSame(StrokeLineCap::ROUND, $out->strokeLineCap);
    }

    public function testFillRuleEvenodd(): void
    {
        $out = StyleResolver::resolve(SvgPaint::default(), ['fill-rule' => 'evenodd'], '', SvgColor::black());
        self::assertSame(FillRule::EVENODD, $out->fillRule);
    }

    public function testStrokeDashArray(): void
    {
        $out = StyleResolver::resolve(
            SvgPaint::default(),
            ['stroke-dasharray' => '4 2 1 2'],
            '',
            SvgColor::black(),
        );
        self::assertSame([4.0, 2.0, 1.0, 2.0], $out->strokeDashArray);
    }

    public function testFillOpacityAndOpacity(): void
    {
        $out = StyleResolver::resolve(
            SvgPaint::default(),
            ['fill-opacity' => '0.5', 'opacity' => '0.5'],
            '',
            SvgColor::black(),
        );
        self::assertSame(0.5, $out->fillOpacity);
        self::assertSame(0.5, $out->opacity);
        self::assertEqualsWithDelta(0.25, $out->effectiveFillOpacity(), 1e-9);
    }

    public function testRgbaSplitsAlphaIntoFillOpacity(): void
    {
        // rgba(255, 0, 0, 0.4): color = red, fill-opacity = 0.4.
        $out = StyleResolver::resolve(
            SvgPaint::default(),
            ['fill' => 'rgba(255, 0, 0, 0.4)'],
            '',
            SvgColor::black(),
        );
        self::assertNotNull($out->fill);
        self::assertSame(0.4, $out->fillOpacity);
    }

    public function testCurrentColorResolvedFromParam(): void
    {
        $cur = new SvgColor(1.0, 0.5, 0.0);
        $out = StyleResolver::resolve(
            SvgPaint::default(),
            ['fill' => 'currentColor'],
            '',
            $cur,
        );
        self::assertNotNull($out->fill);
        self::assertEquals($cur, $out->fill);
    }

    public function testInheritedFillStaysWhenAttrAbsent(): void
    {
        $red = new SvgColor(1.0, 0.0, 0.0);
        $inherited = SvgPaint::default()->withFill($red);
        $out = StyleResolver::resolve($inherited, [], '', SvgColor::black());
        self::assertNotNull($out->fill);
        self::assertEquals($red, $out->fill);
    }
}
