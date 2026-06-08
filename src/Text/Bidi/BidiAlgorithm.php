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

    /**
     * Canonical singleton equivalences relevant to bracket matching (BD16): a
     * left/right angle bracket pairs with its CJK angle-bracket counterpart.
     * codepoint => canonical codepoint used when comparing brackets.
     */
    private const array CANONICAL_BRACKET = [
        0x2329 => 0x3008, 0x232A => 0x3009,
    ];

    /** BD16 caps the bracket-pair stack at 63 entries. */
    private const int BRACKET_STACK_LIMIT = 63;

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
        // Snapshot the original classes before W1 mutates NSM; rule N0 needs to
        // know which characters were NSM to propagate a bracket resolution.
        $originalTypes = $types;

        $types = self::resolveWeak($types, $paragraphLevel);
        $types = self::resolveNeutral($cps, $types, $originalTypes, $paragraphLevel);

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
     * @param array<int, string> $originalTypes
     * @return array<int, string>
     */
    private static function resolveNeutral(array $cps, array $types, array $originalTypes, int $paragraphLevel): array
    {
        $n = count($types);
        $e = $paragraphLevel % 2 === 1 ? 'R' : 'L';
        $sor = $e;
        $eor = $e;

        $types = self::resolveBrackets($cps, $types, $originalTypes, $e);

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
     * Rule N0: resolve paired brackets. Identify bracket pairs by BD16 (a
     * stack of at most 63 open brackets, popped on a matching close under
     * canonical equivalence), then resolve each pair (in order of its opening
     * position) from the strong type enclosed or the preceding context, and
     * propagate the result onto any immediately following original-NSM run.
     *
     * @param list<int> $cps
     * @param array<int, string> $types
     * @param array<int, string> $originalTypes
     * @return array<int, string>
     */
    private static function resolveBrackets(array $cps, array $types, array $originalTypes, string $e): array
    {
        $n = count($cps);
        /** @var list<array{0:int,1:int}> $stack open index, canonical close cp */
        $stack = [];
        /** @var list<array{0:int,1:int}> $pairs open index, close index */
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] !== 'ON') {
                continue;
            }
            $cp = $cps[$i];
            if (isset(BidiBracketData::OPEN_TO_CLOSE[$cp])) {
                if (count($stack) >= self::BRACKET_STACK_LIMIT) {
                    // BD16: stack overflow stops bracket-pair identification.
                    break;
                }
                $stack[] = [$i, self::canonicalBracket(BidiBracketData::OPEN_TO_CLOSE[$cp])];
            } else {
                $cpCanon = self::canonicalBracket($cp);
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s][1] === $cpCanon) {
                        $pairs[] = [$stack[$s][0], $i];
                        $stack = array_slice($stack, 0, $s);
                        break;
                    }
                }
            }
        }

        // N0 resolves pairs in order of their opening bracket position so that
        // an enclosing pair already resolved by the time an inner pair consults
        // the preceding strong context.
        usort($pairs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

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
            $resolved = null;
            if ($foundE) {
                $resolved = $e;
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
            }
            if ($resolved !== null) {
                $types[$open] = $resolved;
                $types[$close] = $resolved;
                self::propagateBracketToNsm($types, $originalTypes, $open, $resolved);
                self::propagateBracketToNsm($types, $originalTypes, $close, $resolved);
            }
        }

        return $types;
    }

    /**
     * N0 tail clause: characters that were originally NSM and immediately
     * follow a bracket whose type changed under N0 take that bracket's type.
     *
     * @param array<int, string> $types
     * @param array<int, string> $originalTypes
     */
    private static function propagateBracketToNsm(array &$types, array $originalTypes, int $bracketIndex, string $resolved): void
    {
        $n = count($types);
        for ($k = $bracketIndex + 1; $k < $n && $originalTypes[$k] === 'NSM'; $k++) {
            $types[$k] = $resolved;
        }
    }

    private static function canonicalBracket(int $cp): int
    {
        return self::CANONICAL_BRACKET[$cp] ?? $cp;
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
