<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

/**
 * SVG feFlood primitive: fills a new buffer with a uniform RGBA color.
 *
 * @internal
 */
final class Flood
{
    public static function apply(int $w, int $h, float $r, float $g, float $b, float $a): RasterBuffer
    {
        $out = new RasterBuffer($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $out->setPixel($x, $y, $r, $g, $b, $a);
            }
        }

        return $out;
    }
}
