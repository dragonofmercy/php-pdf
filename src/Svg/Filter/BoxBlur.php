<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

/**
 * Three-pass box blur approximation of a Gaussian blur (feGaussianBlur).
 *
 * Operates on premultiplied alpha to avoid dark halos at transparent edges.
 * Out-of-bounds samples contribute 0 (edgeMode "none").
 *
 * @internal
 */
final class BoxBlur
{
    /**
     * Compute the box size d for a given stdDeviation per the SVG spec formula:
     *   d = floor(stdDeviation * 3 * sqrt(2*pi) / 4 + 0.5)
     *
     * Returns 0 when stdDeviation <= 0.
     */
    public static function boxSizeFor(float $stdDeviation): int
    {
        if ($stdDeviation <= 0.0) {
            return 0;
        }

        return (int) floor($stdDeviation * (3.0 * sqrt(2.0 * M_PI) / 4.0) + 0.5);
    }

    /**
     * Apply a separable three-pass box blur to $in using the given standard deviations.
     *
     * stdX / stdY == 0 means identity on that axis.
     * Returns a new RasterBuffer; the input is not modified.
     */
    public static function apply(RasterBuffer $in, float $stdX, float $stdY): RasterBuffer
    {
        $w = $in->width;
        $h = $in->height;
        $size = $w * $h;

        // Build premultiplied planes (row-major)
        $pr = [];
        $pg = [];
        $pb = [];
        $pa = [];

        for ($i = 0; $i < $size; $i++) {
            $pr[$i] = 0.0;
            $pg[$i] = 0.0;
            $pb[$i] = 0.0;
            $pa[$i] = 0.0;
        }

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $pixel = $in->pixel($x, $y);
                $r = $pixel[0];
                $g = $pixel[1];
                $b = $pixel[2];
                $a = $pixel[3];
                $idx = $y * $w + $x;
                $pr[$idx] = $r * $a;
                $pg[$idx] = $g * $a;
                $pb[$idx] = $b * $a;
                $pa[$idx] = $a;
            }
        }

        // Horizontal blur
        if ($stdX > 0.0) {
            $dx = self::boxSizeFor($stdX);
            if ($dx > 0) {
                [$l1, $r1, $l2, $r2, $l3, $r3] = self::threePassRadii($dx);
                self::blurPlaneH($pr, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
                self::blurPlaneH($pg, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
                self::blurPlaneH($pb, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
                self::blurPlaneH($pa, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
            }
        }

        // Vertical blur
        if ($stdY > 0.0) {
            $dy = self::boxSizeFor($stdY);
            if ($dy > 0) {
                [$l1, $r1, $l2, $r2, $l3, $r3] = self::threePassRadii($dy);
                self::blurPlaneV($pr, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
                self::blurPlaneV($pg, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
                self::blurPlaneV($pb, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
                self::blurPlaneV($pa, $h, $w, $l1, $r1, $l2, $r2, $l3, $r3);
            }
        }

        // Build output buffer: un-premultiply
        $out = new RasterBuffer($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $idx = $y * $w + $x;
                $a = $pa[$idx];
                if ($a > 0.0) {
                    $r = self::clamp($pr[$idx] / $a);
                    $g = self::clamp($pg[$idx] / $a);
                    $b = self::clamp($pb[$idx] / $a);
                } else {
                    $r = 0.0;
                    $g = 0.0;
                    $b = 0.0;
                }
                $out->setPixel($x, $y, $r, $g, $b, self::clamp($a));
            }
        }

        return $out;
    }

    /**
     * Apply three horizontal box passes in-place to a flat RGBA plane.
     *
     * @param array<int, float> $plane
     */
    private static function blurPlaneH(array &$plane, int $h, int $w, int $l1, int $r1, int $l2, int $r2, int $l3, int $r3): void
    {
        for ($y = 0; $y < $h; $y++) {
            $row = self::extractRow($plane, $y, $w);
            $row = self::boxPass1D($row, $l1, $r1);
            $row = self::boxPass1D($row, $l2, $r2);
            $row = self::boxPass1D($row, $l3, $r3);
            self::writeRow($plane, $y, $w, $row);
        }
    }

    /**
     * Apply three vertical box passes in-place to a flat RGBA plane.
     *
     * @param array<int, float> $plane
     */
    private static function blurPlaneV(array &$plane, int $h, int $w, int $l1, int $r1, int $l2, int $r2, int $l3, int $r3): void
    {
        for ($x = 0; $x < $w; $x++) {
            $col = self::extractCol($plane, $x, $w, $h);
            $col = self::boxPass1D($col, $l1, $r1);
            $col = self::boxPass1D($col, $l2, $r2);
            $col = self::boxPass1D($col, $l3, $r3);
            self::writeCol($plane, $x, $w, $h, $col);
        }
    }

    /**
     * Compute the (leftRadius, rightRadius) pairs for the three box passes.
     *
     * For odd d:   all three passes are symmetric, radius = (d-1)/2 each side.
     * For even d:  pass1 left=d/2, right=d/2-1
     *              pass2 left=d/2-1, right=d/2
     *              pass3 uses box size d+1 (odd), radius = d/2 each side.
     *
     * @return array{int, int, int, int, int, int}
     */
    private static function threePassRadii(int $d): array
    {
        if ($d % 2 === 1) {
            $r = ($d - 1) / 2;
            return [$r, $r, $r, $r, $r, $r];
        }

        $half = $d / 2;
        // pass3 box size = d+1 (odd), radius = (d+1-1)/2 = d/2
        return [$half, $half - 1, $half - 1, $half, $half, $half];
    }

    /**
     * One box-blur pass over a 1D array using a sliding running sum.
     * $left and $right are the left/right radii (box size = left + right + 1).
     * Out-of-bounds samples contribute 0.
     *
     * @param array<int, float> $data
     * @return array<int, float>
     */
    private static function boxPass1D(array $data, int $left, int $right): array
    {
        $n = count($data);
        $boxSize = $left + $right + 1;
        $result = [];

        // Seed the running sum: samples from -$left to $right (clamped to [0, n-1])
        $sum = 0.0;
        for ($k = -$left; $k <= $right; $k++) {
            $sum += ($k >= 0 && $k < $n) ? $data[$k] : 0.0;
        }

        for ($i = 0; $i < $n; $i++) {
            $result[$i] = $sum / $boxSize;
            // Add the sample entering from the right
            $entering = $i + $right + 1;
            $sum += ($entering >= 0 && $entering < $n) ? $data[$entering] : 0.0;
            // Remove the sample leaving from the left
            $leaving = $i - $left;
            $sum -= ($leaving >= 0 && $leaving < $n) ? $data[$leaving] : 0.0;
        }

        return $result;
    }

    /**
     * Extract a row from a flat plane array.
     *
     * @param array<int, float> $plane
     * @return array<int, float>
     */
    private static function extractRow(array $plane, int $y, int $w): array
    {
        $row = [];
        $base = $y * $w;
        for ($x = 0; $x < $w; $x++) {
            $row[$x] = $plane[$base + $x];
        }
        return $row;
    }

    /**
     * Write a row back into a flat plane array.
     *
     * @param array<int, float> $plane
     * @param array<int, float> $row
     */
    private static function writeRow(array &$plane, int $y, int $w, array $row): void
    {
        $base = $y * $w;
        for ($x = 0; $x < $w; $x++) {
            $plane[$base + $x] = $row[$x];
        }
    }

    /**
     * Extract a column from a flat plane array.
     *
     * @param array<int, float> $plane
     * @return array<int, float>
     */
    private static function extractCol(array $plane, int $x, int $w, int $h): array
    {
        $col = [];
        for ($y = 0; $y < $h; $y++) {
            $col[$y] = $plane[$y * $w + $x];
        }
        return $col;
    }

    /**
     * Write a column back into a flat plane array.
     *
     * @param array<int, float> $plane
     * @param array<int, float> $col
     */
    private static function writeCol(array &$plane, int $x, int $w, int $h, array $col): void
    {
        for ($y = 0; $y < $h; $y++) {
            $plane[$y * $w + $x] = $col[$y];
        }
    }

    private static function clamp(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }
}
