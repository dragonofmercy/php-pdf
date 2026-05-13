<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\ColorParser;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class ColorParserTest extends TestCase
{
    public function testNoneReturnsNull(): void
    {
        self::assertNull(ColorParser::parse('none', SvgColor::black()));
    }

    public function testUrlRefReturnsNull(): void
    {
        // Gradient/pattern not supported -> caller falls back per spec.
        self::assertNull(ColorParser::parse('url(#grad)', SvgColor::black()));
    }

    public function testCurrentColorReturnsCurrent(): void
    {
        $current = new SvgColor(1.0, 0.0, 0.0);
        $c = ColorParser::parse('currentColor', $current);
        self::assertNotNull($c);
        self::assertEquals($current, $c);
    }

    public function testHex3(): void
    {
        $c = ColorParser::parse('#f0a', SvgColor::black());
        self::assertNotNull($c);
        self::assertEqualsWithDelta(1.0, $c->r, 1e-9);
        self::assertEqualsWithDelta(0.0, $c->g, 1e-9);
        self::assertEqualsWithDelta(170.0 / 255.0, $c->b, 1e-9);
    }

    public function testHex6(): void
    {
        $c = ColorParser::parse('#FF8800', SvgColor::black());
        self::assertNotNull($c);
        self::assertEqualsWithDelta(1.0, $c->r, 1e-9);
        self::assertEqualsWithDelta(0x88 / 255.0, $c->g, 1e-9);
        self::assertEqualsWithDelta(0.0, $c->b, 1e-9);
    }

    public function testRgbIntegers(): void
    {
        $c = ColorParser::parse('rgb(255, 0, 128)', SvgColor::black());
        self::assertNotNull($c);
        self::assertEqualsWithDelta(1.0, $c->r, 1e-9);
        self::assertEqualsWithDelta(0.0, $c->g, 1e-9);
        self::assertEqualsWithDelta(128.0 / 255.0, $c->b, 1e-9);
    }

    public function testRgbPercentages(): void
    {
        $c = ColorParser::parse('rgb(100%, 50%, 0%)', SvgColor::black());
        self::assertNotNull($c);
        self::assertEqualsWithDelta(1.0, $c->r, 1e-9);
        self::assertEqualsWithDelta(0.5, $c->g, 1e-9);
        self::assertEqualsWithDelta(0.0, $c->b, 1e-9);
    }

    public function testRgbaIgnoresAlphaForColor(): void
    {
        $c = ColorParser::parse('rgba(255, 0, 0, 0.5)', SvgColor::black());
        self::assertNotNull($c);
        self::assertEqualsWithDelta(1.0, $c->r, 1e-9);
    }

    public function testParseAlpha(): void
    {
        self::assertSame(0.5, ColorParser::parseAlpha('rgba(0, 0, 0, 0.5)'));
        self::assertSame(1.0, ColorParser::parseAlpha('rgb(0, 0, 0)'));
        self::assertSame(1.0, ColorParser::parseAlpha('#abc'));
        self::assertSame(0.25, ColorParser::parseAlpha('rgba(0, 0, 0, 25%)'));
    }

    public function testNamedColorsRedGreenBlue(): void
    {
        $r = ColorParser::parse('red', SvgColor::black());
        $g = ColorParser::parse('green', SvgColor::black());
        $b = ColorParser::parse('blue', SvgColor::black());
        self::assertNotNull($r);
        self::assertNotNull($g);
        self::assertNotNull($b);
        self::assertEqualsWithDelta(1.0, $r->r, 1e-9);
        self::assertEqualsWithDelta(128.0 / 255.0, $g->g, 1e-9);
        self::assertEqualsWithDelta(1.0, $b->b, 1e-9);
    }

    public function testNamedColorIsCaseInsensitive(): void
    {
        $a = ColorParser::parse('RED', SvgColor::black());
        $b = ColorParser::parse('Red', SvgColor::black());
        $c = ColorParser::parse('red', SvgColor::black());
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotNull($c);
        self::assertEquals($a, $b);
        self::assertEquals($b, $c);
    }

    public function testInvalidColorReturnsNull(): void
    {
        // Unknown color name / malformed input -> null. Caller decides fallback.
        self::assertNull(ColorParser::parse('chartreusey', SvgColor::black()));
        self::assertNull(ColorParser::parse('#zz', SvgColor::black()));
    }
}
