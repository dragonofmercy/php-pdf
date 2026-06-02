<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * SVG feMerge primitive: composites a stack of buffers using source-over.
 *
 * Layer 0 is the bottom-most; each subsequent layer is composited on top.
 * Channels are straight (non-premultiplied) RGBA floats in [0, 1].
 *
 * @internal
 */
final class Merge
{
    /**
     * @param list<RasterBuffer> $layers
     */
    public static function apply(array $layers): RasterBuffer
    {
        if ($layers === []) {
            throw new PdfException('Merge::apply requires at least one layer, got 0');
        }

        $w = $layers[0]->width;
        $h = $layers[0]->height;

        foreach ($layers as $i => $layer) {
            if ($layer->width !== $w || $layer->height !== $h) {
                throw new PdfException(sprintf(
                    'Merge::apply layer %d dimensions %dx%d do not match base %dx%d',
                    $i,
                    $layer->width,
                    $layer->height,
                    $w,
                    $h,
                ));
            }
        }

        $acc = new RasterBuffer($w, $h);

        foreach ($layers as $layer) {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    [$cb, $gb, $bb, $ab] = $acc->pixel($x, $y);
                    [$cs, $gs, $bs, $as] = $layer->pixel($x, $y);

                    $ar = $as + $ab * (1.0 - $as);

                    if ($ar > 0.0) {
                        $cr = ($cs * $as + $cb * $ab * (1.0 - $as)) / $ar;
                        $cg = ($gs * $as + $gb * $ab * (1.0 - $as)) / $ar;
                        $cbOut = ($bs * $as + $bb * $ab * (1.0 - $as)) / $ar;
                    } else {
                        $cr = 0.0;
                        $cg = 0.0;
                        $cbOut = 0.0;
                    }

                    $acc->setPixel($x, $y, $cr, $cg, $cbOut, $ar);
                }
            }
        }

        return $acc;
    }
}
