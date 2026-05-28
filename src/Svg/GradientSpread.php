<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Rewrites a non-pad gradient into an equivalent pad-mode gradient with
 * extended coordinates and a replicated stop sequence. The output is then
 * rendered by the existing ShadingBuilder unchanged. Identity passthrough
 * for PAD, degenerate inputs, and pathological period counts > 256.
 *
 * @internal
 */
final class GradientSpread
{
    private const int MAX_PERIODS = 256;

    public static function expand(SvgGradient $gradient, BoundingBox $bbox): SvgGradient
    {
        if ($gradient->spreadMethod() === SpreadMethod::PAD) {
            return $gradient;
        }
        if ($gradient instanceof LinearGradient) {
            return self::expandLinear($gradient, $bbox);
        }
        if ($gradient instanceof RadialGradient) {
            return self::expandRadial($gradient, $bbox);
        }
        return $gradient;
    }

    private static function expandLinear(LinearGradient $g, BoundingBox $bbox): SvgGradient
    {
        $vx = $g->x2 - $g->x1;
        $vy = $g->y2 - $g->y1;
        $l2 = $vx * $vx + $vy * $vy;
        if ($l2 <= 0.0) {
            return $g;
        }
        $project = static fn (float $cx, float $cy): float =>
            (($cx - $g->x1) * $vx + ($cy - $g->y1) * $vy) / $l2;
        $t0   = $project($bbox->x, $bbox->y);
        $t1   = $project($bbox->x + $bbox->width, $bbox->y);
        $t2   = $project($bbox->x, $bbox->y + $bbox->height);
        $t3   = $project($bbox->x + $bbox->width, $bbox->y + $bbox->height);
        $tmin = min($t0, $t1, $t2, $t3);
        $tmax = max($t0, $t1, $t2, $t3);
        // Snap t values within floating-point noise of an integer to that integer
        // before computing floor/ceil, so that exact-integer projections (e.g. a
        // corner landing precisely on a period boundary) are not rounded the wrong
        // way by accumulated IEEE 754 rounding error.
        $snapEps = 1e-9;
        $tminRounded = round($tmin);
        $tmaxRounded = round($tmax);
        $tminEff = abs($tmin - $tminRounded) < $snapEps ? $tminRounded : $tmin;
        $tmaxEff = abs($tmax - $tmaxRounded) < $snapEps ? $tmaxRounded : $tmax;
        $kBack = max(0, -(int) floor($tminEff));
        $kFwd  = max(0, (int) ceil($tmaxEff) - 1);
        $n = 1 + $kBack + $kFwd;
        if ($n > self::MAX_PERIODS) {
            return $g;
        }
        $newX1 = $g->x1 - $kBack * $vx;
        $newY1 = $g->y1 - $kBack * $vy;
        $newX2 = $g->x2 + $kFwd * $vx;
        $newY2 = $g->y2 + $kFwd * $vy;
        $stops = self::replicateStops($g->stops(), $n, $kBack, $g->spreadMethod());
        return new LinearGradient(
            $newX1,
            $newY1,
            $newX2,
            $newY2,
            $g->units(),
            $g->transform(),
            $stops,
            $g->uniformOpacity(),
            SpreadMethod::PAD,
        );
    }

    private static function expandRadial(RadialGradient $g, BoundingBox $bbox): SvgGradient
    {
        // Implemented in Task 5.
        return $g;
    }

    /**
     * Emits N copies of the original stops list, mapped to N equal sub-ranges
     * of [0,1]. Period direction alternates for REFLECT (aligned so the
     * original period at index kBack is always forward); always forward for
     * REPEAT. Duplicate offsets at period seams are intentional and valid:
     * for REPEAT they encode the hard color step between periods; for REFLECT
     * the duplicated stops share a color and form a degenerate zero-width
     * interval that PDF readers handle (the existing normalizeStops already
     * produces duplicate-offset lists from non-monotonic input).
     *
     * @param list<GradientStop> $stops original normalized stops (covers [0,1])
     * @return list<GradientStop>
     */
    private static function replicateStops(array $stops, int $n, int $kBack, SpreadMethod $spread): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $forward = $spread === SpreadMethod::REPEAT
                ? true
                : (($i - $kBack) % 2 + 2) % 2 === 0;
            $iter = $forward
                ? $stops
                : array_reverse($stops);
            foreach ($iter as $s) {
                $base = $forward ? $s->offset : 1.0 - $s->offset;
                $out[] = new GradientStop(($i + $base) / $n, $s->color, $s->opacity);
            }
        }
        return $out;
    }
}
