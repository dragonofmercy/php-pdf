<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Text\Arabic;

/**
 * Pure-PHP Arabic cursive shaper. Rewrites a logical-order string so Arabic
 * letters use their contextual presentation forms (isolated/initial/medial/
 * final) and lam-alef pairs become their mandatory ligatures, using the
 * generated ArabicShapingData tables.
 *
 * Runs BEFORE measurement and bidi reordering: it operates in logical order,
 * the Unicode-correct stage for cursive joining. A fast scan returns the input
 * byte-for-byte when there is no Arabic, so non-Arabic output is unchanged.
 *
 * @internal
 */
final class ArabicShaper
{
    private const int FORM_ISOLATED = 0;
    private const int FORM_INITIAL = 1;
    private const int FORM_MEDIAL = 2;
    private const int FORM_FINAL = 3;

    public static function shape(string $logical): string
    {
        // Fast path: nothing in the Arabic block 0600..06FF means no shaping.
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $logical)) {
            return $logical;
        }

        /** @var list<int> $cps */
        $cps = [];
        foreach (mb_str_split($logical, 1, 'UTF-8') as $c) {
            $cps[] = mb_ord($c, 'UTF-8');
        }

        $out = [];
        $count = count($cps);
        for ($i = 0; $i < $count; $i++) {
            $cp = $cps[$i];
            $forms = ArabicShapingData::FORMS[$cp] ?? null;
            if ($forms === null) {
                // Not a shapable Arabic letter (mark, punctuation, non-Arabic).
                $out[] = $cp;
                continue;
            }

            $ownType = self::joiningType($cp);
            $prevType = self::neighbourType($cps, $i, -1);
            $joinsRight = self::joinsOnRightSide($ownType) && self::joinsOnLeftSide($prevType);

            // Mandatory lam-alef ligature: lam (0644) immediately followed
            // (across transparent marks) by an alef variant present in LAM_ALEF.
            if ($cp === 0x0644) {
                $alefIndex = self::nextNonTransparentIndex($cps, $i);
                if ($alefIndex !== null) {
                    $ligature = ArabicShapingData::LAM_ALEF[$cps[$alefIndex]] ?? null;
                    if ($ligature !== null) {
                        // [isolated, final]: final when a preceding letter joins.
                        $out[] = $joinsRight ? $ligature[1] : $ligature[0];
                        // Preserve any transparent marks that sat between lam and alef.
                        for ($k = $i + 1; $k < $alefIndex; $k++) {
                            $out[] = $cps[$k];
                        }
                        $i = $alefIndex; // consume the alef
                        continue;
                    }
                }
            }

            $nextType = self::neighbourType($cps, $i, +1);
            $joinsLeft = self::joinsOnLeftSide($ownType) && self::joinsOnRightSide($nextType);

            $position = match (true) {
                $joinsRight && $joinsLeft => self::FORM_MEDIAL,
                $joinsLeft => self::FORM_INITIAL,
                $joinsRight => self::FORM_FINAL,
                default => self::FORM_ISOLATED,
            };

            $out[] = self::pickForm($forms, $position, $cp);
        }

        $s = '';
        foreach ($out as $cp) {
            $s .= mb_chr($cp, 'UTF-8');
        }
        return $s;
    }

    /** Joining type of a codepoint; default 'U' (non-joining). */
    private static function joiningType(int $cp): string
    {
        return ArabicShapingData::JOINING_TYPES[$cp] ?? 'U';
    }

    /**
     * Joining type of the nearest non-transparent neighbour in the given
     * direction (+1 next, -1 previous). Transparent (T) marks are skipped.
     * Returns 'U' past the string edge.
     *
     * @param list<int> $cps
     */
    private static function neighbourType(array $cps, int $i, int $dir): string
    {
        $count = count($cps);
        for ($j = $i + $dir; $j >= 0 && $j < $count; $j += $dir) {
            $t = self::joiningType($cps[$j]);
            if ($t !== 'T') {
                return $t;
            }
        }
        return 'U';
    }

    /**
     * Index of the next non-transparent codepoint after $i, or null at the edge.
     *
     * @param list<int> $cps
     */
    private static function nextNonTransparentIndex(array $cps, int $i): ?int
    {
        $count = count($cps);
        for ($j = $i + 1; $j < $count; $j++) {
            if (self::joiningType($cps[$j]) !== 'T') {
                return $j;
            }
        }
        return null;
    }

    /** A joining type that connects on its right side: R, D, or C. */
    private static function joinsOnRightSide(string $type): bool
    {
        return $type === 'R' || $type === 'D' || $type === 'C';
    }

    /** A joining type that connects on its left side: L, D, or C. */
    private static function joinsOnLeftSide(string $type): bool
    {
        return $type === 'L' || $type === 'D' || $type === 'C';
    }

    /**
     * Return the presentation form at the requested position, falling back to a
     * simpler form when the requested one is absent (final->isolated,
     * medial->initial->isolated). Returns the base codepoint if even isolated
     * is absent.
     *
     * @param list<int> $forms [isolated, initial, medial, final]
     */
    private static function pickForm(array $forms, int $position, int $base): int
    {
        $chain = match ($position) {
            self::FORM_MEDIAL => [self::FORM_MEDIAL, self::FORM_INITIAL, self::FORM_ISOLATED],
            self::FORM_FINAL => [self::FORM_FINAL, self::FORM_ISOLATED],
            self::FORM_INITIAL => [self::FORM_INITIAL, self::FORM_ISOLATED],
            default => [self::FORM_ISOLATED],
        };
        foreach ($chain as $p) {
            if ($forms[$p] !== 0) {
                return $forms[$p];
            }
        }
        return $base;
    }
}
