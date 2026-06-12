<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Flatten;

/**
 * Computes the ISO 32000-1 12.5.5 matrix that maps an appearance stream's
 * bounding box (after its own /Matrix) onto a widget annotation's /Rect, so the
 * appearance can be drawn into page content with a single `cm` operator before
 * the `Do`. The XObject's own /Matrix is applied internally by `Do`, so the
 * returned matrix maps the transformed-appearance box, not the raw BBox.
 *
 * @internal
 */
final class AppearancePlacement
{
    /**
     * @param list<float> $bbox   appearance /BBox [llx, lly, urx, ury]
     * @param list<float> $matrix appearance /Matrix [a, b, c, d, e, f] (identity when absent)
     * @param list<float> $rect   widget /Rect, corner-normalized [llx, lly, urx, ury]
     * @return list<float> the cm matrix [a, b, c, d, e, f]
     */
    public static function matrix(array $bbox, array $matrix, array $rect): array
    {
        [$bx0, $by0, $bx1, $by1] = $bbox;
        [$a, $b, $c, $d, $e, $f] = $matrix;

        $xs = [];
        $ys = [];
        foreach ([[$bx0, $by0], [$bx1, $by0], [$bx1, $by1], [$bx0, $by1]] as [$x, $y]) {
            $xs[] = $a * $x + $c * $y + $e;
            $ys[] = $b * $x + $d * $y + $f;
        }
        $tllx = min($xs);
        $turx = max($xs);
        $tlly = min($ys);
        $tury = max($ys);
        $boxW = $turx - $tllx;
        $boxH = $tury - $tlly;

        [$r0x, $r0y, $r1x, $r1y] = $rect;
        $rllx = min($r0x, $r1x);
        $rlly = min($r0y, $r1y);
        $rectW = abs($r1x - $r0x);
        $rectH = abs($r1y - $r0y);

        $sx = $boxW != 0.0 ? $rectW / $boxW : 1.0;
        $sy = $boxH != 0.0 ? $rectH / $boxH : 1.0;

        return [$sx, 0.0, 0.0, $sy, $rllx - $sx * $tllx, $rlly - $sy * $tlly];
    }
}
