<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * SVG feBlend primitive: composites two buffers using an SVG 1.1 blend mode.
 *
 * $in is the source (top layer, cs/as); $in2 is the backdrop (cb/ab).
 * Buffers carry straight (non-premultiplied) RGBA floats in [0, 1], but the
 * SVG 1.1 feBlend formulas are defined on PREMULTIPLIED color and produce a
 * premultiplied result, so each channel is premultiplied on the way in and
 * un-premultiplied on the way out.
 *
 * SVG 1.1 feBlend formulas (premultiplied cs/cb, qa = source alpha, qb = backdrop alpha):
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
                [$rs, $gs, $bs, $as] = $in->pixel($x, $y);
                [$rb, $gb, $bb, $ab] = $in2->pixel($x, $y);

                $ar = $as + $ab - $as * $ab;
                $ar = RasterBuffer::clamp01($ar);

                // Blend per channel on premultiplied color, then un-premultiply.
                $cr = self::blendChannel($rs * $as, $rb * $ab, $as, $ab, $mode);
                $cg = self::blendChannel($gs * $as, $gb * $ab, $as, $ab, $mode);
                $cbOut = self::blendChannel($bs * $as, $bb * $ab, $as, $ab, $mode);

                $inv = $ar > 0.0 ? 1.0 / $ar : 0.0;
                $out->setPixel(
                    $x,
                    $y,
                    RasterBuffer::clamp01($cr * $inv),
                    RasterBuffer::clamp01($cg * $inv),
                    RasterBuffer::clamp01($cbOut * $inv),
                    $ar,
                );
            }
        }

        return $out;
    }

    /**
     * Applies the SVG 1.1 feBlend mode to a single premultiplied channel pair.
     */
    private static function blendChannel(float $cs, float $cb, float $qa, float $qb, BlendMode $mode): float
    {
        return match ($mode) {
            BlendMode::NORMAL => (1.0 - $qa) * $cb + $cs,
            BlendMode::MULTIPLY => (1.0 - $qa) * $cb + (1.0 - $qb) * $cs + $cs * $cb,
            BlendMode::SCREEN => $cs + $cb - $cs * $cb,
            BlendMode::DARKEN => min((1.0 - $qa) * $cb + $cs, (1.0 - $qb) * $cs + $cb),
            BlendMode::LIGHTEN => max((1.0 - $qa) * $cb + $cs, (1.0 - $qb) * $cs + $cb),
        };
    }
}
