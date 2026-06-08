<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Text\Bidi;

use DragonOfMercy\PhpPdf\Text\Direction;

/**
 * Render-facing seam over the UBA. Resolves a concrete base direction and
 * turns a single logical-order line into a visual-order string. A fast RTL
 * pre-scan short-circuits the LTR-with-no-RTL case to return the input
 * byte-for-byte, so non-RTL output is unchanged.
 *
 * @internal
 */
final class BidiProcessor
{
    /**
     * Collapse a requested direction to a concrete LTR/RTL. AUTO derives the
     * paragraph level from the first strong character (rules P2/P3): the first
     * L gives LTR, the first R/AL gives RTL, none gives LTR.
     */
    public static function resolveBaseDirection(string $text, Direction $requested): Direction
    {
        if ($requested !== Direction::AUTO) {
            return $requested;
        }
        foreach (self::codepoints($text) as $cp) {
            $class = BidiCharacterType::of($cp);
            if ($class === 'L') {
                return Direction::LTR;
            }
            if ($class === 'R' || $class === 'AL') {
                return Direction::RTL;
            }
        }
        return Direction::LTR;
    }

    /**
     * Reorder one logical-order line to visual order for the given concrete
     * base direction (LTR or RTL; AUTO is treated as LTR - callers resolve it
     * first via resolveBaseDirection()).
     */
    public static function reorder(string $line, Direction $base): string
    {
        if ($line === '') {
            return $line;
        }
        // Fast path: a pure-ASCII line with a non-RTL base has no RTL content and
        // no reordering to do; skip the UTF-8 decode entirely. (preg_match returns
        // 0 when the line is all bytes 0x00-0x7F.)
        if ($base !== Direction::RTL && preg_match('/[^\x00-\x7F]/', $line) === 0) {
            return $line;
        }
        $cps = self::codepoints($line);
        if ($base !== Direction::RTL && !self::hasRtl($cps)) {
            return $line; // byte-identity fast path
        }
        $paragraphLevel = $base === Direction::RTL ? 1 : 0;
        $levels = BidiAlgorithm::resolveLevels($cps, $paragraphLevel);
        $visual = BidiAlgorithm::reorderLine($cps, $levels, $paragraphLevel);
        $out = '';
        foreach ($visual as $cp) {
            $out .= mb_chr($cp, 'UTF-8');
        }
        return $out;
    }

    /** @param list<int> $cps */
    private static function hasRtl(array $cps): bool
    {
        foreach ($cps as $cp) {
            $class = BidiCharacterType::of($cp);
            if ($class === 'R' || $class === 'AL' || $class === 'AN') {
                return true;
            }
        }
        return false;
    }

    /** @return list<int> */
    private static function codepoints(string $text): array
    {
        $out = [];
        foreach (mb_str_split($text, 1, 'UTF-8') as $c) {
            $out[] = mb_ord($c, 'UTF-8');
        }
        return $out;
    }
}
