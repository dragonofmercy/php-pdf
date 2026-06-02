<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * SVG feComposite filter primitive.
 *
 * Composites two RGBA float buffers using Porter-Duff operators or the SVG
 * arithmetic formula. Both buffers must share the same dimensions.
 *
 * Input channels are straight (non-premultiplied). Internally the math
 * operates on premultiplied values and the result is un-premultiplied before
 * being written to the output RasterBuffer.
 *
 * @internal
 */
final class Composite
{
    /**
     * Apply feComposite to two buffers.
     *
     * $in  is the source (i1 / "in" attribute in SVG).
     * $in2 is the destination / backdrop (i2 / "in2" attribute in SVG).
     *
     * $k1..$k4 are only used for ARITHMETIC; ignored for Porter-Duff operators.
     *
     * @throws PdfException When the two buffers have different dimensions.
     */
    public static function apply(RasterBuffer $in, RasterBuffer $in2, CompositeOperator $op, float $k1, float $k2, float $k3, float $k4): RasterBuffer
    {
        if ($in->width !== $in2->width || $in->height !== $in2->height) {
            throw new PdfException(sprintf(
                'feComposite: source size %dx%d does not match destination size %dx%d',
                $in->width,
                $in->height,
                $in2->width,
                $in2->height,
            ));
        }

        $w = $in->width;
        $h = $in->height;
        $out = new RasterBuffer($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                [$rs, $gs, $bs, $as] = $in->pixel($x, $y);
                [$rd, $gd, $bd, $ad] = $in2->pixel($x, $y);

                // Premultiply source and destination.
                $rSrc = $rs * $as;
                $gSrc = $gs * $as;
                $bSrc = $bs * $as;

                $rDst = $rd * $ad;
                $gDst = $gd * $ad;
                $bDst = $bd * $ad;

                if ($op === CompositeOperator::ARITHMETIC) {
                    // out = k1*i1*i2 + k2*i1 + k3*i2 + k4 applied per channel and alpha.
                    $rOut = self::clamp($k1 * $rSrc * $rDst + $k2 * $rSrc + $k3 * $rDst + $k4);
                    $gOut = self::clamp($k1 * $gSrc * $gDst + $k2 * $gSrc + $k3 * $gDst + $k4);
                    $bOut = self::clamp($k1 * $bSrc * $bDst + $k2 * $bSrc + $k3 * $bDst + $k4);
                    $aOut = self::clamp($k1 * $as * $ad + $k2 * $as + $k3 * $ad + $k4);
                } else {
                    [$fa, $fb] = self::factors($op, $as, $ad);

                    $rOut = $rSrc * $fa + $rDst * $fb;
                    $gOut = $gSrc * $fa + $gDst * $fb;
                    $bOut = $bSrc * $fa + $bDst * $fb;
                    $aOut = $as * $fa + $ad * $fb;
                }

                // Un-premultiply.
                if ($aOut > 0.0) {
                    $rFinal = self::clamp($rOut / $aOut);
                    $gFinal = self::clamp($gOut / $aOut);
                    $bFinal = self::clamp($bOut / $aOut);
                } else {
                    $rFinal = 0.0;
                    $gFinal = 0.0;
                    $bFinal = 0.0;
                }

                $out->setPixel($x, $y, $rFinal, $gFinal, $bFinal, self::clamp($aOut));
            }
        }

        return $out;
    }

    /**
     * Return the (Fa, Fb) Porter-Duff factors for a given operator.
     *
     * @return array{0: float, 1: float}
     */
    private static function factors(CompositeOperator $op, float $aSrc, float $aDst): array
    {
        return match ($op) {
            CompositeOperator::OVER => [1.0, 1.0 - $aSrc],
            CompositeOperator::IN   => [$aDst, 0.0],
            CompositeOperator::OUT  => [1.0 - $aDst, 0.0],
            CompositeOperator::ATOP => [$aDst, 1.0 - $aSrc],
            CompositeOperator::XOR  => [1.0 - $aDst, 1.0 - $aSrc],
            CompositeOperator::ARITHMETIC => [0.0, 0.0], // unreachable; handled above
        };
    }

    private static function clamp(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }
}
