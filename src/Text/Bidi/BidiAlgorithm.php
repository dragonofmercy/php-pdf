<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Text\Bidi;

/**
 * Unicode Bidirectional Algorithm (UAX #9), implicit part only. Operates on a
 * single line of codepoints given a paragraph embedding level. No explicit
 * embedding/override/isolate controls (Phase A scope): those rare format
 * characters keep their resolved class and are not specially processed.
 *
 * @internal
 */
final class BidiAlgorithm
{
    /** Common mirrored bracket pairs (rule L4). codepoint => mirror codepoint. */
    private const array MIRROR = [
        0x0028 => 0x0029, 0x0029 => 0x0028,
        0x005B => 0x005D, 0x005D => 0x005B,
        0x007B => 0x007D, 0x007D => 0x007B,
        0x003C => 0x003E, 0x003E => 0x003C,
        0x00AB => 0x00BB, 0x00BB => 0x00AB,
        0x2039 => 0x203A, 0x203A => 0x2039,
    ];

    /** Bracket pairs for rule N0 (BD14/BD15/BD16), open => close. */
    private const array BRACKET_PAIRS = [
        0x0028 => 0x0029, 0x005B => 0x005D, 0x007B => 0x007D,
    ];

    /**
     * Resolve the embedding level of every codepoint on a line.
     *
     * @param list<int> $cps
     * @return list<int> one level per codepoint
     */
    public static function resolveLevels(array $cps, int $paragraphLevel): array
    {
        $n = count($cps);
        if ($n === 0) {
            return [];
        }

        /** @var list<string> $types */
        $types = [];
        foreach ($cps as $cp) {
            $types[] = BidiCharacterType::of($cp);
        }

        $types = self::resolveWeak($types, $paragraphLevel);
        $types = self::resolveNeutral($cps, $types, $paragraphLevel);

        return self::resolveImplicit($types, $paragraphLevel);
    }

    /**
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private static function resolveWeak(array $types, int $paragraphLevel): array
    {
        $n = count($types);
        $sor = $paragraphLevel % 2 === 1 ? 'R' : 'L';

        // W1: NSM -> type of previous char (or sor at start).
        $prev = $sor;
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] === 'NSM') {
                $types[$i] = $prev;
            }
            $prev = $types[$i];
        }

        // W2: EN -> AN if the previous strong type is AL.
        $strong = $sor;
        for ($i = 0; $i < $n; $i++) {
            $t = $types[$i];
            if ($t === 'R' || $t === 'L' || $t === 'AL') {
                $strong = $t;
            } elseif ($t === 'EN' && $strong === 'AL') {
                $types[$i] = 'AN';
            }
        }

        // W3: AL -> R.
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] === 'AL') {
                $types[$i] = 'R';
            }
        }

        // W4: single ES between two EN -> EN; single CS between two same number type -> that type.
        for ($i = 1; $i < $n - 1; $i++) {
            $t = $types[$i];
            $p = $types[$i - 1];
            $q = $types[$i + 1];
            if ($t === 'ES' && $p === 'EN' && $q === 'EN') {
                $types[$i] = 'EN';
            } elseif ($t === 'CS' && $p === $q && ($p === 'EN' || $p === 'AN')) {
                $types[$i] = $p;
            }
        }

        // W5: a sequence of ET adjacent to EN -> EN.
        $i = 0;
        while ($i < $n) {
            if ($types[$i] === 'ET') {
                $start = $i;
                while ($i < $n && $types[$i] === 'ET') {
                    $i++;
                }
                $before = $start > 0 ? $types[$start - 1] : $sor;
                $after = $i < $n ? $types[$i] : $sor;
                if ($before === 'EN' || $after === 'EN') {
                    for ($k = $start; $k < $i; $k++) {
                        $types[$k] = 'EN';
                    }
                }
            } else {
                $i++;
            }
        }

        // W6: remaining ES/ET/CS -> ON.
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] === 'ES' || $types[$i] === 'ET' || $types[$i] === 'CS') {
                $types[$i] = 'ON';
            }
        }

        // W7: EN -> L if the previous strong type is L.
        $strong = $sor;
        for ($i = 0; $i < $n; $i++) {
            $t = $types[$i];
            if ($t === 'R' || $t === 'L') {
                $strong = $t;
            } elseif ($t === 'EN' && $strong === 'L') {
                $types[$i] = 'L';
            }
        }

        return $types;
    }

    /**
     * @param list<int> $cps
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private static function resolveNeutral(array $cps, array $types, int $paragraphLevel): array
    {
        $n = count($types);
        $e = $paragraphLevel % 2 === 1 ? 'R' : 'L';
        $sor = $e;
        $eor = $e;

        $types = self::resolveBrackets($cps, $types, $e);

        $neutral = ['ON', 'WS', 'B', 'S'];
        $i = 0;
        while ($i < $n) {
            if (in_array($types[$i], $neutral, true)) {
                $start = $i;
                while ($i < $n && in_array($types[$i], $neutral, true)) {
                    $i++;
                }
                $before = $start > 0 ? self::dirClass($types[$start - 1]) : $sor;
                $after = $i < $n ? self::dirClass($types[$i]) : $eor;
                $resolved = ($before === $after && ($before === 'L' || $before === 'R'))
                    ? $before
                    : $e;
                for ($k = $start; $k < $i; $k++) {
                    $types[$k] = $resolved;
                }
            } else {
                $i++;
            }
        }

        return $types;
    }

    private static function dirClass(string $type): string
    {
        return match ($type) {
            'L' => 'L',
            'R', 'EN', 'AN' => 'R',
            default => $type,
        };
    }

    /**
     * @param list<int> $cps
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private static function resolveBrackets(array $cps, array $types, string $e): array
    {
        $n = count($cps);
        /** @var list<array{0:int,1:int}> $stack */
        $stack = [];
        /** @var list<array{0:int,1:int}> $pairs */
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] !== 'ON') {
                continue;
            }
            $cp = $cps[$i];
            if (isset(self::BRACKET_PAIRS[$cp])) {
                $stack[] = [$i, self::BRACKET_PAIRS[$cp]];
            } else {
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s][1] === $cp) {
                        $pairs[] = [$stack[$s][0], $i];
                        $stack = array_slice($stack, 0, $s);
                        break;
                    }
                }
            }
        }

        $opposite = $e === 'L' ? 'R' : 'L';
        foreach ($pairs as [$open, $close]) {
            $foundE = false;
            $foundOpp = false;
            for ($k = $open + 1; $k < $close; $k++) {
                $d = self::dirClass($types[$k]);
                if ($d === $e) {
                    $foundE = true;
                    break;
                }
                if ($d === $opposite) {
                    $foundOpp = true;
                }
            }
            if ($foundE) {
                $types[$open] = $e;
                $types[$close] = $e;
            } elseif ($foundOpp) {
                $ctx = $e;
                for ($k = $open - 1; $k >= 0; $k--) {
                    $d = self::dirClass($types[$k]);
                    if ($d === 'L' || $d === 'R') {
                        $ctx = $d;
                        break;
                    }
                }
                $resolved = $ctx === $opposite ? $opposite : $e;
                $types[$open] = $resolved;
                $types[$close] = $resolved;
            }
        }

        return $types;
    }

    /**
     * @param array<int, string> $types
     * @return list<int>
     */
    private static function resolveImplicit(array $types, int $paragraphLevel): array
    {
        $n = count($types);
        $levels = [];
        for ($i = 0; $i < $n; $i++) {
            $level = $paragraphLevel;
            $t = $types[$i];
            if ($level % 2 === 0) {
                if ($t === 'R') {
                    $level += 1;
                } elseif ($t === 'AN' || $t === 'EN') {
                    $level += 2;
                }
            } else {
                if ($t === 'L' || $t === 'EN' || $t === 'AN') {
                    $level += 1;
                }
            }
            $levels[] = $level;
        }

        return $levels;
    }

    /**
     * Apply line rules L1 (reset trailing whitespace/separators to the
     * paragraph level), L2 (reorder by level), then L4 (mirror brackets at odd
     * levels). Returns visual-order codepoints.
     *
     * @param list<int> $cps
     * @param list<int> $levels
     * @return list<int>
     */
    public static function reorderLine(array $cps, array $levels, int $paragraphLevel): array
    {
        $n = count($cps);
        if ($n === 0) {
            return [];
        }

        // L1: reset separators and trailing whitespace to the paragraph level.
        $resetFrom = $n;
        for ($i = $n - 1; $i >= 0; $i--) {
            $type = BidiCharacterType::of($cps[$i]);
            if ($type === 'WS' || $type === 'B' || $type === 'S') {
                $resetFrom = $i;
            } else {
                break;
            }
        }
        for ($i = $resetFrom; $i < $n; $i++) {
            $levels[$i] = $paragraphLevel;
        }

        // L2: from the highest level down to the lowest odd level, reverse any
        // contiguous run of characters at that level or higher.
        $order = range(0, $n - 1);
        $maxLevel = $paragraphLevel;
        foreach ($levels as $lvl) {
            if ($lvl > $maxLevel) {
                $maxLevel = $lvl;
            }
        }
        $minOdd = $maxLevel + 1;
        foreach ($levels as $lvl) {
            if ($lvl % 2 === 1 && $lvl < $minOdd) {
                $minOdd = $lvl;
            }
        }
        for ($lvl = $maxLevel; $lvl >= $minOdd; $lvl--) {
            $i = 0;
            while ($i < $n) {
                if ($levels[$order[$i]] >= $lvl) {
                    $start = $i;
                    while ($i < $n && $levels[$order[$i]] >= $lvl) {
                        $i++;
                    }
                    $slice = array_reverse(array_slice($order, $start, $i - $start));
                    array_splice($order, $start, count($slice), $slice);
                } else {
                    $i++;
                }
            }
        }

        $visual = [];
        foreach ($order as $idx) {
            $cp = $cps[$idx];
            if ($levels[$idx] % 2 === 1 && isset(self::MIRROR[$cp])) {
                $cp = self::MIRROR[$cp];
            }
            $visual[] = $cp;
        }
        return $visual;
    }
}
