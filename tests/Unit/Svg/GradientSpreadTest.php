<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\BoundingBox;
use DragonOfMercy\PhpPdf\Svg\GradientSpread;
use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\SpreadMethod;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class GradientSpreadTest extends TestCase
{
    private const float EPS = 1e-9;

    /** @return list<GradientStop> */
    private function blackWhiteStops(): array
    {
        return [
            new GradientStop(0.0, SvgColor::fromBytes(0, 0, 0), 1.0),
            new GradientStop(1.0, SvgColor::fromBytes(255, 255, 255), 1.0),
        ];
    }

    public function testPadIsIdentity(): void
    {
        $g = new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::PAD);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertSame($g, $out);
    }

    public function testDegenerateLinearIsIdentity(): void
    {
        $g = new LinearGradient(0.5, 0.5, 0.5, 0.5, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REPEAT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertSame($g, $out);
    }

    public function testLinearRepeatExtendsForwardOnly(): void
    {
        // Gradient axis 0..0.5 along x; bbox is unit square so tmax = 2 -> kFwd = 1, N = 2.
        $g = new LinearGradient(0.0, 0.0, 0.5, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REPEAT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertInstanceOf(LinearGradient::class, $out);
        self::assertSame(SpreadMethod::PAD, $out->spreadMethod());
        self::assertEqualsWithDelta(0.0, $out->x1, self::EPS);
        self::assertEqualsWithDelta(1.0, $out->x2, self::EPS);
        // 2 periods, repeat -> forward, forward. Stops: [0->0.5] then [0.5->1.0].
        $offsets = array_map(static fn ($s) => $s->offset, $out->stops());
        self::assertEqualsWithDelta(0.0, $offsets[0], self::EPS);
        self::assertEqualsWithDelta(0.5, $offsets[1], self::EPS);
        self::assertEqualsWithDelta(0.5, $offsets[2], self::EPS);
        self::assertEqualsWithDelta(1.0, $offsets[3], self::EPS);
        // First period: black -> white. Second: black -> white again.
        self::assertEqualsWithDelta(0.0, $out->stops()[0]->color->r, self::EPS);
        self::assertEqualsWithDelta(1.0, $out->stops()[1]->color->r, self::EPS);
        self::assertEqualsWithDelta(0.0, $out->stops()[2]->color->r, self::EPS);
        self::assertEqualsWithDelta(1.0, $out->stops()[3]->color->r, self::EPS);
    }

    public function testLinearReflectAlternatesDirections(): void
    {
        // Gradient axis 0..0.5 along x; bbox unit square -> N = 2.
        $g = new LinearGradient(0.0, 0.0, 0.5, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REFLECT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertInstanceOf(LinearGradient::class, $out);
        // Period 0 forward (black->white), Period 1 backward (white->black).
        self::assertEqualsWithDelta(0.0, $out->stops()[0]->color->r, self::EPS);
        self::assertEqualsWithDelta(1.0, $out->stops()[1]->color->r, self::EPS);
        self::assertEqualsWithDelta(1.0, $out->stops()[2]->color->r, self::EPS);
        self::assertEqualsWithDelta(0.0, $out->stops()[3]->color->r, self::EPS);
    }

    public function testLinearRepeatExtendsBothDirections(): void
    {
        // Gradient axis from (0.4, 0) to (0.6, 0) -> length 0.2; bbox unit square
        // tmin = (0-0.4)/0.2 = -2, tmax = (1-0.4)/0.2 = 3. kBack=2, kFwd=2, N=5.
        $g = new LinearGradient(0.4, 0.0, 0.6, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REPEAT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertInstanceOf(LinearGradient::class, $out);
        self::assertEqualsWithDelta(0.0, $out->x1, self::EPS);
        self::assertEqualsWithDelta(1.0, $out->x2, self::EPS);
        // 5 periods x 2 stops each = 10 stops emitted, no seam dedup (duplicate
        // offsets with hard-step colors are valid PDF; ShadingBuilder already
        // produces duplicate-offset stop lists from normalizeStops).
        self::assertCount(10, $out->stops());
        // First period (forward, no backward shift): black@0 -> white@0.2.
        $stops = $out->stops();
        self::assertEqualsWithDelta(0.0, $stops[0]->color->r, self::EPS);
        self::assertEqualsWithDelta(1.0, $stops[1]->color->r, self::EPS);
    }

    public function testLinearReflectAlignsOriginalPeriodForward(): void
    {
        // Same setup as testLinearRepeatExtendsBothDirections, but with reflect.
        // kBack=2 means original period sits at index 2 (out of 5). Reflect aligns
        // so period index 2 is forward -> (i - kBack) % 2 == 0 -> i in {0, 2, 4} forward.
        $g = new LinearGradient(0.4, 0.0, 0.6, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REFLECT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertInstanceOf(LinearGradient::class, $out);
        $stops = $out->stops();
        // 5 periods x 2 stops = 10 entries. Colors alternate at every pair of stops:
        // Period 0 forward (black, white), Period 1 backward (white, black),
        // Period 2 forward (black, white), Period 3 backward (white, black),
        // Period 4 forward (black, white).
        self::assertCount(10, $stops);
        $expectedR = [0.0, 1.0, 1.0, 0.0, 0.0, 1.0, 1.0, 0.0, 0.0, 1.0];
        foreach ($expectedR as $i => $r) {
            self::assertEqualsWithDelta($r, $stops[$i]->color->r, self::EPS, "stop $i red channel");
        }
    }

    public function testLinearAboveCapIsIdentity(): void
    {
        // A gradient with axis length 1e-3 against a bbox of width 1 needs ~1000 periods -> over the 256 cap -> identity.
        $g = new LinearGradient(0.0, 0.0, 0.001, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REPEAT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertSame($g, $out);
    }

    public function testRadialPadIsIdentity(): void
    {
        $g = new \DragonOfMercy\PhpPdf\Svg\RadialGradient(0.5, 0.5, 0.5, 0.5, 0.5, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::PAD);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertSame($g, $out);
    }

    public function testRadialDegenerateIsIdentity(): void
    {
        $g = new \DragonOfMercy\PhpPdf\Svg\RadialGradient(0.5, 0.5, 0.0, 0.5, 0.5, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REPEAT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertSame($g, $out);
    }

    public function testRadialRepeatExtendsOuterRadius(): void
    {
        // Focal at center (0.5, 0.5), r = 0.25, bbox unit square -> max distance to corner = sqrt(0.5)/2 * 2 = ~0.707, N = ceil(0.707/0.25) = 3.
        $g = new \DragonOfMercy\PhpPdf\Svg\RadialGradient(0.5, 0.5, 0.25, 0.5, 0.5, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REPEAT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\RadialGradient::class, $out);
        self::assertSame(SpreadMethod::PAD, $out->spreadMethod());
        self::assertEqualsWithDelta(0.75, $out->r, self::EPS); // 0.25 * 3
        self::assertEqualsWithDelta(0.5, $out->cx, self::EPS);
        self::assertEqualsWithDelta(0.5, $out->cy, self::EPS);
        self::assertEqualsWithDelta(0.5, $out->fx, self::EPS);
        self::assertEqualsWithDelta(0.5, $out->fy, self::EPS);
        // 3 periods x 2 stops = 6 stops at 0, 1/3, 1/3, 2/3, 2/3, 1.
        self::assertCount(6, $out->stops());
    }

    public function testRadialReflectAlternates(): void
    {
        $g = new \DragonOfMercy\PhpPdf\Svg\RadialGradient(0.5, 0.5, 0.25, 0.5, 0.5, GradientUnits::OBJECT_BOUNDING_BOX, null, $this->blackWhiteStops(), 1.0, SpreadMethod::REFLECT);
        $out = GradientSpread::expand($g, new BoundingBox(0.0, 0.0, 1.0, 1.0));
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\RadialGradient::class, $out);
        $stops = $out->stops();
        // 3 periods x 2 stops = 6 entries. Period 0 forward (black, white),
        // period 1 backward (white, black), period 2 forward (black, white).
        self::assertCount(6, $stops);
        $expectedR = [0.0, 1.0, 1.0, 0.0, 0.0, 1.0];
        foreach ($expectedR as $i => $r) {
            self::assertEqualsWithDelta($r, $stops[$i]->color->r, self::EPS, "stop $i red channel");
        }
    }
}
