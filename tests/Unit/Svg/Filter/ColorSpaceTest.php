<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\ColorSpace;
use PHPUnit\Framework\TestCase;

final class ColorSpaceTest extends TestCase
{
    public function testEndpointsAreFixed(): void
    {
        self::assertSame(0.0, ColorSpace::srgbToLinear(0.0));
        self::assertSame(1.0, ColorSpace::srgbToLinear(1.0));
        self::assertSame(0.0, ColorSpace::linearToSrgb(0.0));
        self::assertSame(1.0, ColorSpace::linearToSrgb(1.0));
    }

    public function testRoundTrip(): void
    {
        foreach ([0.05, 0.2, 0.5, 0.75, 0.95] as $v) {
            self::assertEqualsWithDelta($v, ColorSpace::linearToSrgb(ColorSpace::srgbToLinear($v)), 1e-9);
        }
    }

    public function testKnownMidpoint(): void
    {
        self::assertEqualsWithDelta(0.21404114, ColorSpace::srgbToLinear(0.5), 1e-6);
    }
}
