<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\FillRule;

/**
 * Fills polygon rings into a RasterBuffer with anti-aliasing via fixed 4x4 integer supersampling.
 *
 * Rings are in device pixel coordinates. Output is byte-identical on every platform because the
 * 16 sub-sample positions are computed from fixed integer offsets, not platform floating-point AA.
 *
 * @internal
 */
final class PolygonFiller
{
    /**
     * Fill polygon rings into the buffer using the given fill rule and RGBA color.
     *
     * @param list<list<array{x: float, y: float}>> $rings
     */
    public static function fill(RasterBuffer $buf, array $rings, FillRule $rule, float $r, float $g, float $b, float $a): void
    {
        if ($rings === []) {
            return;
        }

        // Compute bounding box of all ring points.
        $minX = PHP_FLOAT_MAX;
        $minY = PHP_FLOAT_MAX;
        $maxX = -PHP_FLOAT_MAX;
        $maxY = -PHP_FLOAT_MAX;

        foreach ($rings as $ring) {
            foreach ($ring as $pt) {
                if ($pt['x'] < $minX) {
                    $minX = $pt['x'];
                }
                if ($pt['y'] < $minY) {
                    $minY = $pt['y'];
                }
                if ($pt['x'] > $maxX) {
                    $maxX = $pt['x'];
                }
                if ($pt['y'] > $maxY) {
                    $maxY = $pt['y'];
                }
            }
        }

        // Clamp to valid pixel indices.
        $pxMin = max(0, (int) floor($minX));
        $pyMin = max(0, (int) floor($minY));
        $pxMax = min($buf->width - 1, (int) floor($maxX));
        $pyMax = min($buf->height - 1, (int) floor($maxY));

        if ($pxMin > $pxMax || $pyMin > $pyMax) {
            return;
        }

        // Precompute flat edge list: [x1, y1, x2, y2] for all rings.
        /** @var list<array{float, float, float, float}> $edges */
        $edges = [];
        foreach ($rings as $ring) {
            $n = count($ring);
            if ($n < 2) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                $p1 = $ring[$i];
                $p2 = $ring[($i + 1) % $n];
                $edges[] = [$p1['x'], $p1['y'], $p2['x'], $p2['y']];
            }
        }

        if ($edges === []) {
            return;
        }

        for ($py = $pyMin; $py <= $pyMax; $py++) {
            for ($px = $pxMin; $px <= $pxMax; $px++) {
                $covered = 0;

                for ($sy = 0; $sy < 4; $sy++) {
                    for ($sx = 0; $sx < 4; $sx++) {
                        $sampleX = $px + ($sx + 0.5) / 4.0;
                        $sampleY = $py + ($sy + 0.5) / 4.0;

                        $inside = false;

                        if ($rule === FillRule::EVENODD) {
                            $parity = false;
                            foreach ($edges as [$x1, $y1, $x2, $y2]) {
                                if (($y1 > $sampleY) !== ($y2 > $sampleY)) {
                                    $xIntersect = ($x2 - $x1) * ($sampleY - $y1) / ($y2 - $y1) + $x1;
                                    if ($sampleX < $xIntersect) {
                                        $parity = !$parity;
                                    }
                                }
                            }
                            $inside = $parity;
                        } else {
                            // NONZERO winding number.
                            $winding = 0;
                            foreach ($edges as [$x1, $y1, $x2, $y2]) {
                                if ($y1 <= $sampleY) {
                                    if ($y2 > $sampleY) {
                                        // Upward crossing: check if point is left of edge.
                                        $isLeft = ($x2 - $x1) * ($sampleY - $y1) - ($sampleX - $x1) * ($y2 - $y1);
                                        if ($isLeft > 0.0) {
                                            $winding++;
                                        }
                                    }
                                } else {
                                    if ($y2 <= $sampleY) {
                                        // Downward crossing: check if point is right of edge.
                                        $isLeft = ($x2 - $x1) * ($sampleY - $y1) - ($sampleX - $x1) * ($y2 - $y1);
                                        if ($isLeft < 0.0) {
                                            $winding--;
                                        }
                                    }
                                }
                            }
                            $inside = $winding !== 0;
                        }

                        if ($inside) {
                            $covered++;
                        }
                    }
                }

                if ($covered === 0) {
                    continue;
                }

                $coverage = $covered / 16.0;
                $srcA = $a * $coverage;

                // Source-over composite (straight RGBA).
                [$dstR, $dstG, $dstB, $dstA] = $buf->pixel($px, $py);
                $outA = $srcA + $dstA * (1.0 - $srcA);

                if ($outA > 0.0) {
                    $inv = 1.0 / $outA;
                    $outR = ($r * $srcA + $dstR * $dstA * (1.0 - $srcA)) * $inv;
                    $outG = ($g * $srcA + $dstG * $dstA * (1.0 - $srcA)) * $inv;
                    $outB = ($b * $srcA + $dstB * $dstA * (1.0 - $srcA)) * $inv;
                } else {
                    $outR = 0.0;
                    $outG = 0.0;
                    $outB = 0.0;
                }

                $buf->setPixel($px, $py, $outR, $outG, $outB, $outA);
            }
        }
    }
}
