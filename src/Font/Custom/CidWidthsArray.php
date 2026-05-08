<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Generates the /W array PDF syntax for a CIDFont, with run compression.
 * Two forms (PDF spec 9.7.4.3):
 *   [first last width]              -- constant width run
 *   [first [w1 w2 ... wN]]          -- heterogeneous widths
 *
 * Widths are scaled from the font's native unitsPerEm to 1000 em units.
 *
 * @internal
 */
final class CidWidthsArray
{
    private const int RANGE_THRESHOLD = 4;

    public static function generate(ParsedTtf $font): string
    {
        $widths = $font->advanceWidthsByGid;
        if ($widths === []) {
            return '[]';
        }
        ksort($widths);

        $scaled = [];
        foreach ($widths as $gid => $w) {
            $scaled[$gid] = (int) round($w * 1000.0 / $font->unitsPerEm);
        }

        $segments = [];
        $gids = array_keys($scaled);
        $n = count($gids);

        $i = 0;
        while ($i < $n) {
            $startGid = $gids[$i];
            $width = $scaled[$startGid];

            $j = $i + 1;
            while ($j < $n
                && $gids[$j] === $gids[$j - 1] + 1
                && $scaled[$gids[$j]] === $width
            ) {
                $j++;
            }
            $constantRunLength = $j - $i;

            if ($constantRunLength >= self::RANGE_THRESHOLD) {
                $segments[] = $startGid . ' ' . $gids[$j - 1] . ' ' . $width;
                $i = $j;
                continue;
            }

            $values = [$width];
            $k = $i + 1;
            while ($k < $n && $gids[$k] === $gids[$k - 1] + 1) {
                $w = $scaled[$gids[$k]];
                $constantAhead = 1;
                $m = $k + 1;
                while ($m < $n
                    && $gids[$m] === $gids[$m - 1] + 1
                    && $scaled[$gids[$m]] === $w
                ) {
                    $constantAhead++;
                    $m++;
                }
                if ($constantAhead >= self::RANGE_THRESHOLD) {
                    break;
                }
                $values[] = $w;
                $k++;
            }
            $segments[] = $startGid . ' [' . implode(' ', $values) . ']';
            $i = $k;
        }

        return '[' . implode(' ', $segments) . ']';
    }
}
