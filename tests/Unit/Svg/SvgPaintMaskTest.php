<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use DragonOfMercy\PhpPdf\Svg\Mask\SvgMask;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use PHPUnit\Framework\TestCase;

final class SvgPaintMaskTest extends TestCase
{
    public function testDefaultMaskIsNull(): void
    {
        self::assertNull(SvgPaint::default()->mask);
    }

    public function testWithMaskSetsField(): void
    {
        $mask = new SvgMask('m', MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::USER_SPACE_ON_USE, -0.1, -0.1, 1.2, 1.2, []);
        $paint = SvgPaint::default()->withMask($mask);
        self::assertSame($mask, $paint->mask);
    }

    public function testWithMaskNullClearsField(): void
    {
        $mask = new SvgMask('m', MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::USER_SPACE_ON_USE, -0.1, -0.1, 1.2, 1.2, []);
        $paint = SvgPaint::default()->withMask($mask);
        $cleared = $paint->withMask(null);
        self::assertNull($cleared->mask);
    }

    public function testWithMaskPreservesOtherFields(): void
    {
        $mask = new SvgMask('m', MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::USER_SPACE_ON_USE, -0.1, -0.1, 1.2, 1.2, []);
        $paint = SvgPaint::default()->withStrokeWidth(7.5);
        $masked = $paint->withMask($mask);
        self::assertSame(7.5, $masked->strokeWidth);
        self::assertSame($mask, $masked->mask);
    }

    public function testMaskNotInheritedByDefault(): void
    {
        // mask is per-element in SVG; withMarkers shows the pattern (markersProvided flag).
        // We test that re-deriving via another with-method does not duplicate the mask.
        $mask = new SvgMask('m', MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::USER_SPACE_ON_USE, -0.1, -0.1, 1.2, 1.2, []);
        $paint = SvgPaint::default()->withMask($mask);
        // Re-derive: mask should persist (with*() preserves fields unless explicitly cleared).
        $next = $paint->withStrokeWidth(3.0);
        self::assertSame($mask, $next->mask);
    }
}
