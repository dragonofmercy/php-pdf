<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\SvgTextStyle;
use DragonOfMercy\PhpPdf\Svg\TextAnchor;
use DragonOfMercy\PhpPdf\Svg\TextStyleResolver;
use PHPUnit\Framework\TestCase;

final class TextStyleResolverTest extends TestCase
{
    public function testInheritedValuesArePreservedWhenNothingSet(): void
    {
        $parent = new SvgTextStyle('serif', 20.0, true, false, TextAnchor::MIDDLE);
        $child = TextStyleResolver::resolve($parent, [], [], '');
        self::assertSame('serif', $child->fontFamily);
        self::assertSame(20.0, $child->fontSize);
        self::assertTrue($child->bold);
        self::assertSame(TextAnchor::MIDDLE, $child->anchor);
    }

    public function testPresentationAttributesApply(): void
    {
        $style = TextStyleResolver::resolve(
            SvgTextStyle::initial(),
            ['font-family' => 'Arial', 'font-size' => '24', 'font-weight' => 'bold', 'text-anchor' => 'end'],
            [],
            '',
        );
        self::assertSame('Arial', $style->fontFamily);
        self::assertSame(24.0, $style->fontSize);
        self::assertTrue($style->bold);
        self::assertFalse($style->italic);
        self::assertSame(TextAnchor::END, $style->anchor);
    }

    public function testInlineStyleBeatsPresentationAttribute(): void
    {
        $style = TextStyleResolver::resolve(
            SvgTextStyle::initial(),
            ['font-size' => '10'],
            [],
            'font-size: 30px; font-style: italic',
        );
        self::assertSame(30.0, $style->fontSize);
        self::assertTrue($style->italic);
    }

    public function testEmFontSizeIsRelativeToInherited(): void
    {
        $parent = new SvgTextStyle('sans-serif', 20.0, false, false, TextAnchor::START);
        $style = TextStyleResolver::resolve($parent, ['font-size' => '1.5em'], [], '');
        self::assertSame(30.0, $style->fontSize);
    }

    public function testPercentFontSizeIsRelativeToInherited(): void
    {
        $parent = new SvgTextStyle('sans-serif', 20.0, false, false, TextAnchor::START);
        $style = TextStyleResolver::resolve($parent, ['font-size' => '50%'], [], '');
        self::assertSame(10.0, $style->fontSize);
    }

    public function testNumericWeightAtLeast600IsBold(): void
    {
        $style = TextStyleResolver::resolve(SvgTextStyle::initial(), ['font-weight' => '700'], [], '');
        self::assertTrue($style->bold);
        $light = TextStyleResolver::resolve(SvgTextStyle::initial(), ['font-weight' => '300'], [], '');
        self::assertFalse($light->bold);
    }

    public function testInvalidFontSizeFallsBackToInherited(): void
    {
        $parent = new SvgTextStyle('sans-serif', 16.0, false, false, TextAnchor::START);
        $style = TextStyleResolver::resolve($parent, ['font-size' => 'huge'], [], '');
        self::assertSame(16.0, $style->fontSize);
    }

    public function testCssBeatsPresentationAttributeForFontSize(): void
    {
        $out = TextStyleResolver::resolve(
            SvgTextStyle::initial(),
            ['font-size' => '10'],
            ['font-size' => '20'],
            '',
        );
        self::assertSame(20.0, $out->fontSize);
    }

    public function testInlineStyleBeatsCssForFontFamily(): void
    {
        $out = TextStyleResolver::resolve(
            SvgTextStyle::initial(),
            [],
            ['font-family' => 'serif'],
            'font-family: monospace',
        );
        self::assertSame('monospace', $out->fontFamily);
    }
}
