<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Builds the inline PDF Shading Pattern dictionary (PatternType 2) for a
 * resolved gradient and a precomputed pattern matrix. Axial gradients use
 * ShadingType 2, radial use ShadingType 3. The stop list (already normalized
 * to span [0,1] and monotonic) becomes a FunctionType 2 for two stops or a
 * stitching FunctionType 3 for more.
 *
 * @internal
 */
final class ShadingBuilder
{
    public static function patternDict(SvgGradient $gradient, SvgMatrix $matrix): string
    {
        $shading = self::shadingDict($gradient);
        return '<< /Type /Pattern /PatternType 2 /Matrix [' . self::matrix($matrix) . '] /Shading ' . $shading . ' >>';
    }

    private static function shadingDict(SvgGradient $gradient): string
    {
        $fn = self::functionDict($gradient->stops());
        if ($gradient instanceof RadialGradient) {
            return '<< /ShadingType 3 /ColorSpace /DeviceRGB /Coords ['
                . Format::num($gradient->fx) . ' ' . Format::num($gradient->fy) . ' 0 '
                . Format::num($gradient->cx) . ' ' . Format::num($gradient->cy) . ' ' . Format::num($gradient->r)
                . '] /Function ' . $fn . ' /Extend [true true] >>';
        }
        if ($gradient instanceof LinearGradient) {
            return '<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords ['
                . Format::num($gradient->x1) . ' ' . Format::num($gradient->y1) . ' '
                . Format::num($gradient->x2) . ' ' . Format::num($gradient->y2)
                . '] /Function ' . $fn . ' /Extend [true true] >>';
        }
        // Unreachable: only Linear/Radial implement SvgGradient. Fail safe with axial defaults.
        return '<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 1 0] /Function ' . $fn . ' /Extend [true true] >>';
    }

    /**
     * Precondition: at least 2 stops (GradientResolver normalizes single/zero
     * stops away before a gradient reaches here).
     *
     * @param list<GradientStop> $stops
     */
    private static function functionDict(array $stops): string
    {
        if (count($stops) === 2) {
            return self::exponential($stops[0]->color, $stops[1]->color);
        }
        $fns = [];
        $bounds = [];
        $encode = [];
        for ($i = 0, $n = count($stops); $i < $n - 1; $i++) {
            $fns[] = self::exponential($stops[$i]->color, $stops[$i + 1]->color);
            if ($i > 0) {
                $bounds[] = Format::num($stops[$i]->offset);
            }
            $encode[] = '0 1';
        }
        return '<< /FunctionType 3 /Domain [0 1] /Functions [' . implode(' ', $fns)
            . '] /Bounds [' . implode(' ', $bounds)
            . '] /Encode [' . implode(' ', $encode) . '] >>';
    }

    private static function exponential(SvgColor $c0, SvgColor $c1): string
    {
        return '<< /FunctionType 2 /Domain [0 1] /C0 ['
            . Format::num($c0->r) . ' ' . Format::num($c0->g) . ' ' . Format::num($c0->b)
            . '] /C1 [' . Format::num($c1->r) . ' ' . Format::num($c1->g) . ' ' . Format::num($c1->b)
            . '] /N 1 >>';
    }

    private static function matrix(SvgMatrix $m): string
    {
        return Format::num($m->a) . ' ' . Format::num($m->b) . ' ' . Format::num($m->c)
            . ' ' . Format::num($m->d) . ' ' . Format::num($m->e) . ' ' . Format::num($m->f);
    }
}
