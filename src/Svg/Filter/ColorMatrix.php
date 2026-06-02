<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/**
 * Applies an feColorMatrix operation to a RasterBuffer.
 *
 * All four ColorMatrixType variants reduce to a 4x5 row-major matrix M where:
 *   out_channel = M_row . [r, g, b, a, 1]
 *
 * Operates on non-premultiplied straight RGBA floats in [0, 1].
 *
 * @internal
 */
final class ColorMatrix
{
    /**
     * @param list<float> $values
     */
    public static function apply(RasterBuffer $in, ColorMatrixType $type, array $values): RasterBuffer
    {
        $m = self::buildMatrix($type, $values);
        $out = new RasterBuffer($in->width, $in->height);

        for ($y = 0; $y < $in->height; $y++) {
            for ($x = 0; $x < $in->width; $x++) {
                [$r, $g, $b, $a] = $in->pixel($x, $y);

                $nr = $m[0] * $r  + $m[1] * $g  + $m[2] * $b  + $m[3] * $a  + $m[4];
                $ng = $m[5] * $r  + $m[6] * $g  + $m[7] * $b  + $m[8] * $a  + $m[9];
                $nb = $m[10] * $r + $m[11] * $g + $m[12] * $b + $m[13] * $a + $m[14];
                $na = $m[15] * $r + $m[16] * $g + $m[17] * $b + $m[18] * $a + $m[19];

                $out->setPixel(
                    $x, $y,
                    self::clamp($nr),
                    self::clamp($ng),
                    self::clamp($nb),
                    self::clamp($na),
                );
            }
        }

        return $out;
    }

    /**
     * Build the 20-entry row-major matrix (4 rows x 5 cols) for the given type.
     *
     * @param list<float> $values
     * @return list<float>
     */
    private static function buildMatrix(ColorMatrixType $type, array $values): array
    {
        return match ($type) {
            ColorMatrixType::MATRIX => self::matrixFromValues($values),
            ColorMatrixType::SATURATE => self::saturateMatrix($values[0] ?? 1.0),
            ColorMatrixType::HUE_ROTATE => self::hueRotateMatrix($values[0] ?? 0.0),
            ColorMatrixType::LUMINANCE_TO_ALPHA => self::luminanceToAlphaMatrix(),
        };
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private static function matrixFromValues(array $values): array
    {
        if (count($values) === 20) {
            return $values;
        }

        // Identity fallback
        return [
            1, 0, 0, 0, 0,
            0, 1, 0, 0, 0,
            0, 0, 1, 0, 0,
            0, 0, 0, 1, 0,
        ];
    }

    /** @return list<float> */
    private static function saturateMatrix(float $s): array
    {
        // SVG spec feColorMatrix type="saturate" coefficients
        return [
            0.213 + 0.787 * $s, 0.715 - 0.715 * $s, 0.072 - 0.072 * $s, 0.0, 0.0,
            0.213 - 0.213 * $s, 0.715 + 0.285 * $s, 0.072 - 0.072 * $s, 0.0, 0.0,
            0.213 - 0.213 * $s, 0.715 - 0.715 * $s, 0.072 + 0.928 * $s, 0.0, 0.0,
            0.0,                0.0,                 0.0,                 1.0, 0.0,
        ];
    }

    /** @return list<float> */
    private static function hueRotateMatrix(float $deg): array
    {
        $rad = deg2rad($deg);
        $c = cos($rad);
        $sn = sin($rad);

        // SVG spec hue-rotate matrix: a + c*b + sn*d
        // a matrix (identity contribution):
        //   [0.213, 0.715, 0.072]
        //   [0.213, 0.715, 0.072]
        //   [0.213, 0.715, 0.072]
        // b matrix (cosine contribution):
        //   [ 0.787, -0.715, -0.072]
        //   [-0.213,  0.285, -0.072]
        //   [-0.213, -0.715,  0.928]
        // d matrix (sine contribution):
        //   [-0.213, 0.140, 0.140]  <- wait, SVG uses:
        //   [ 0.143, 0.140, -0.283]
        //   [-0.787, 0.715,  0.072]  <- not quite; use exact SVG spec values below

        // Exact SVG spec hue-rotate 3x3 (rows = R,G,B; cols = r,g,b input):
        $rr = 0.213 + $c * 0.787  - $sn * 0.213;
        $rg = 0.715 - $c * 0.715  - $sn * 0.715;
        $rb = 0.072 - $c * 0.072  + $sn * 0.928;

        $gr = 0.213 - $c * 0.213  + $sn * 0.143;
        $gg = 0.715 + $c * 0.285  + $sn * 0.140;
        $gb = 0.072 - $c * 0.072  - $sn * 0.283;

        $br = 0.213 - $c * 0.213  - $sn * 0.787;
        $bg = 0.715 - $c * 0.715  + $sn * 0.715;
        $bb = 0.072 + $c * 0.928  + $sn * 0.072;

        return [
            $rr,  $rg,  $rb,  0.0, 0.0,
            $gr,  $gg,  $gb,  0.0, 0.0,
            $br,  $bg,  $bb,  0.0, 0.0,
            0.0,  0.0,  0.0,  1.0, 0.0,
        ];
    }

    /** @return list<float> */
    private static function luminanceToAlphaMatrix(): array
    {
        // SVG spec luminanceToAlpha coefficients
        return [
            0.0,    0.0,    0.0,    0.0, 0.0,
            0.0,    0.0,    0.0,    0.0, 0.0,
            0.0,    0.0,    0.0,    0.0, 0.0,
            0.2125, 0.7154, 0.0721, 0.0, 0.0,
        ];
    }

    private static function clamp(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }
}
