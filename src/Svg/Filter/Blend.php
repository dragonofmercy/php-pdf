<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * SVG feBlend primitive: composites two buffers using an SVG 1.1 blend mode.
 *
 * $in is the source (top layer, cs/as); $in2 is the backdrop (cb/ab).
 * Channels are straight (non-premultiplied) RGBA floats in [0, 1].
 *
 * SVG 1.1 feBlend formulas (non-premultiplied, qa = source alpha, qb = backdrop alpha):
 *   ar = as + ab - as*ab
 *   normal:   cr = (1 - qa)*cb + cs
 *   multiply: cr = (1 - qa)*cb + (1 - qb)*cs + cs*cb
 *   screen:   cr = cs + cb - cs*cb
 *   darken:   cr = min((1 - qa)*cb + cs, (1 - qb)*cs + cb)
 *   lighten:  cr = max((1 - qa)*cb + cs, (1 - qb)*cs + cb)
 *
 * @internal
 */
final class Blend
{
    public static function apply(RasterBuffer $in, RasterBuffer $in2, BlendMode $mode): RasterBuffer
    {
        $w = $in->width;
        $h = $in->height;

        if ($in2->width !== $w || $in2->height !== $h) {
            throw new PdfException(sprintf(
                'Blend::apply: source %dx%d and backdrop %dx%d dimensions differ',
                $w,
                $h,
                $in2->width,
                $in2->height,
            ));
        }

        $out = new RasterBuffer($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                [$cs, $gs, $bs, $as] = $in->pixel($x, $y);
                [$cb, $gb, $bb, $ab] = $in2->pixel($x, $y);

                $qa = $as;
                $qb = $ab;

                $ar = $as + $ab - $as * $ab;
                $ar = $ar < 0.0 ? 0.0 : ($ar > 1.0 ? 1.0 : $ar);

                $cr = self::blendChannel($cs, $cb, $qa, $qb, $mode);
                $cg = self::blendChannel($gs, $gb, $qa, $qb, $mode);
                $cbOut = self::blendChannel($bs, $bb, $qa, $qb, $mode);

                $out->setPixel($x, $y, $cr, $cg, $cbOut, $ar);
            }
        }

        return $out;
    }

    private static function blendChannel(float $cs, float $cb, float $qa, float $qb, BlendMode $mode): float
    {
        $cr = match ($mode) {
            BlendMode::NORMAL => (1.0 - $qa) * $cb + $cs,
            BlendMode::MULTIPLY => (1.0 - $qa) * $cb + (1.0 - $qb) * $cs + $cs * $cb,
            BlendMode::SCREEN => $cs + $cb - $cs * $cb,
            BlendMode::DARKEN => min((1.0 - $qa) * $cb + $cs, (1.0 - $qb) * $cs + $cb),
            BlendMode::LIGHTEN => max((1.0 - $qa) * $cb + $cs, (1.0 - $qb) * $cs + $cb),
        };

        return $cr < 0.0 ? 0.0 : ($cr > 1.0 ? 1.0 : $cr);
    }
}
