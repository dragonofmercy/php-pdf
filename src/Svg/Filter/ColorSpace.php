<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal Component-wise sRGB <-> linearRGB transfer (values clamped to [0,1]). */
final class ColorSpace
{
    public static function srgbToLinear(float $c): float
    {
        if ($c <= 0.0) {
            return 0.0;
        }
        if ($c >= 1.0) {
            return 1.0;
        }
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    public static function linearToSrgb(float $c): float
    {
        if ($c <= 0.0) {
            return 0.0;
        }
        if ($c >= 1.0) {
            return 1.0;
        }
        return $c <= 0.0031308 ? $c * 12.92 : 1.055 * $c ** (1.0 / 2.4) - 0.055;
    }
}
