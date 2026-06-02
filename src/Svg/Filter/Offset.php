<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

/**
 * SVG feOffset primitive: shifts a raster buffer by (dx, dy) pixels.
 *
 * Pixels that shift out of bounds are replaced with transparent black.
 *
 * @internal
 */
final class Offset
{
    public static function apply(RasterBuffer $in, int $dx, int $dy): RasterBuffer
    {
        $w = $in->width;
        $h = $in->height;
        $out = new RasterBuffer($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $sx = $x - $dx;
                $sy = $y - $dy;
                if ($sx >= 0 && $sx < $w && $sy >= 0 && $sy < $h) {
                    [$r, $g, $b, $a] = $in->pixel($sx, $sy);
                    $out->setPixel($x, $y, $r, $g, $b, $a);
                }
            }
        }

        return $out;
    }
}
