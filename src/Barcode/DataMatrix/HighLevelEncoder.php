<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

/**
 * DataMatrix ECC200 high-level encoder (ISO/IEC 16022 5.2).
 *
 * Walks the input string and emits a sequence of codewords. Future tasks
 * will extend this with C40, Text, Base256 and the Annex P shortest-path
 * selector. For now: pure ASCII with digit-pair packing.
 *
 * Entry point: {@see self::encode()}.
 *
 * @internal
 */
final class HighLevelEncoder
{
    // ASCII mode codewords (ISO 16022 5.2.3, Table 6).
    // Other ASCII codewords (PAD=129, FNC1=232) are introduced by later
    // tasks when they become consumed; PHPStan max rejects unused private
    // constants.
    private const int CW_ASCII_DIGIT_PAIR      = 130; // base for digit-pair packing
    private const int CW_ASCII_LATCH_C40       = 230;
    private const int CW_ASCII_LATCH_BASE256   = 231;
    private const int CW_ASCII_EXTENDED_ASCII  = 235;
    private const int CW_ASCII_LATCH_TEXT      = 239;
    private const int CW_ASCII_ECI             = 241; // ECI character (ISO 16022 5.6.1)

    // ECI assignment number for UTF-8 (AIM ECI registry). Encoded as a single
    // codeword (value + 1) since it is <= 126.
    private const int ECI_UTF8 = 26;

    /**
     * Encode the input string into a sequence of DataMatrix codewords using the
     * ISO/IEC 16022 Annex P shortest-path mode selector.
     *
     * Walks the input one position at a time. At each step, projects the
     * codeword cost of continuing in the current mode versus latching to each
     * candidate mode (ASCII / C40 / Text / Base256) over a short lookahead
     * window, and picks the cheapest. On mode change, emits the appropriate
     * latch/unlatch sequence and then encodes the next "unit" (1-2 bytes for
     * ASCII, a run for C40/Text/Base256). At end of input, emits a final
     * unlatch if still in a triplet mode.
     *
     * @param string $input Non-empty byte sequence.
     * @return list<int>    Codewords (each 0-255).
     */
    public static function encode(string $input): array
    {
        $len = strlen($input);
        if ($len === 0) {
            return [];
        }
        $out = [];
        // Declare UTF-8 (ECI 26) for genuine UTF-8 text carrying non-ASCII bytes,
        // so readers interpret it as UTF-8 instead of the default Latin-1 (which
        // renders accents as mojibake). Raw binary (not valid UTF-8) stays
        // charset-less and is treated as opaque bytes. Mirrors the Aztec ECI.
        // ISO/IEC 16022 5.6.1: codeword 241 then (ECI value + 1) for ECI <= 126.
        if (self::shouldDeclareUtf8($input, $len)) {
            $out[] = self::CW_ASCII_ECI;
            $out[] = self::ECI_UTF8 + 1;
        }
        $mode = DataMatrixMode::ASCII;
        $i = 0;
        while ($i < $len) {
            $next = self::lookaheadMode($input, $i, $mode);
            if ($next !== $mode) {
                foreach (self::modeSwitch($mode, $next) as $cw) {
                    $out[] = $cw;
                }
                $mode = $next;
            }
            [$emitted, $consumed, $mode] = self::encodeOneUnit($input, $i, $mode, count($out));
            foreach ($emitted as $cw) {
                $out[] = $cw;
            }
            $i += $consumed;
        }
        if ($mode === DataMatrixMode::C40 || $mode === DataMatrixMode::TEXT) {
            $out[] = 254;
        }
        return $out;
    }

    /**
     * Pick the cheapest mode for the substring starting at $pos, given we are
     * currently in $current. Implements the Annex P lookahead by projecting
     * codeword cost over an 8-byte window.
     */
    private static function lookaheadMode(string $input, int $pos, DataMatrixMode $current): DataMatrixMode
    {
        // NOTE: the cost model below is a faithful heuristic in the spirit of the
        // ISO/IEC 16022 Annex P lookahead test. Magic numbers carry their own
        // inline comments. The output is valid DataMatrix but not necessarily
        // byte-identical to other Annex P implementations.
        $len = strlen($input);
        $window = min(8, $len - $pos); // Annex P canonical 8-byte lookahead window.
        $sub = substr($input, $pos, $window);
        $best = $current;
        $bestCost = self::projectCost($sub, $current, $current);
        foreach ([DataMatrixMode::ASCII, DataMatrixMode::C40, DataMatrixMode::TEXT, DataMatrixMode::BASE256] as $cand) {
            if ($cand === $current) {
                continue;
            }
            $c = self::projectCost($sub, $current, $cand);
            if ($c < $bestCost) {
                $bestCost = $c;
                $best = $cand;
            }
        }
        return $best;
    }

    private static function projectCost(string $sub, DataMatrixMode $from, DataMatrixMode $to): float
    {
        $switchCost = ($from === $to) ? 0.0 : 1.0;
        if (($from === DataMatrixMode::C40 || $from === DataMatrixMode::TEXT) && $to !== $from) {
            // +1 codeword for the unlatch (254) when leaving a triplet mode (ISO 5.2.5.2).
            $switchCost += 1.0;
        }
        return $switchCost + self::modeCost($sub, $to);
    }

    private static function modeCost(string $sub, DataMatrixMode $m): float
    {
        $len = strlen($sub);
        if ($len === 0) {
            return 0.0;
        }
        return match ($m) {
            DataMatrixMode::ASCII   => self::asciiCost($sub, $len),
            // C40 packs 3 values into 2 codewords -> 2/3 cw per value (ISO 16022 5.2.5).
            DataMatrixMode::C40     => self::tripletCost($sub, $len, self::c40Values(...)),
            // Text mode shares C40's triplet packing density, different alphabet (ISO 16022 5.2.6).
            DataMatrixMode::TEXT    => self::tripletCost($sub, $len, self::textValues(...)),
            // 1 codeword per data byte + ~1.5 amortised for the latch and length prefix.
            DataMatrixMode::BASE256 => $len + 1.5,
        };
    }

    private static function asciiCost(string $sub, int $len): float
    {
        $c = 0.0;
        $i = 0;
        while ($i < $len) {
            if ($i + 1 < $len && self::isDigit($sub[$i]) && self::isDigit($sub[$i + 1])) {
                $c += 1.0;
                $i += 2;
            } elseif (ord($sub[$i]) > 0x7F) {
                $c += 2.0;
                $i++;
            } else {
                $c += 1.0;
                $i++;
            }
        }
        return $c;
    }

    /**
     * @param callable(int): list<int> $mapByte
     */
    private static function tripletCost(string $sub, int $len, callable $mapByte): float
    {
        $values = 0;
        for ($i = 0; $i < $len; $i++) {
            $values += count($mapByte(ord($sub[$i])));
        }
        return $values * 2.0 / 3.0;
    }

    /**
     * @return list<int>
     */
    private static function modeSwitch(DataMatrixMode $from, DataMatrixMode $to): array
    {
        $cw = [];
        if ($from === DataMatrixMode::C40 || $from === DataMatrixMode::TEXT) {
            $cw[] = 254;
        }
        return match ($to) {
            DataMatrixMode::ASCII   => $cw,
            DataMatrixMode::C40     => [...$cw, self::CW_ASCII_LATCH_C40],
            DataMatrixMode::TEXT    => [...$cw, self::CW_ASCII_LATCH_TEXT],
            DataMatrixMode::BASE256 => [...$cw, self::CW_ASCII_LATCH_BASE256],
        };
    }

    /**
     * Encode one unit (1-2 bytes in ASCII, a run in C40/Text/Base256) starting
     * at $pos in the given $mode.
     *
     * Returns the emitted codewords, the number of input bytes consumed, and the
     * mode the encoder is actually in afterwards. The trailing mode matters: a
     * C40/Text run with a residual of 1 emits an in-band unlatch (254) and falls
     * back to ASCII for the trailing byte, and a Base256 run auto-returns to
     * ASCII once the announced byte count is read. In both cases the walker must
     * learn that it is back in ASCII so it neither emits a spurious closing
     * unlatch at end of input nor skips a needed latch on the next unit.
     *
     * @param int $emittedCount Number of codewords already emitted (the Base256
     *                          latch is the last of them); used to compute the
     *                          absolute symbol position for Base256 randomization.
     *
     * @return array{list<int>, int, DataMatrixMode}
     */
    private static function encodeOneUnit(string $input, int $pos, DataMatrixMode $mode, int $emittedCount): array
    {
        $len = strlen($input);
        if ($mode === DataMatrixMode::ASCII) {
            if ($pos + 1 < $len && self::isDigit($input[$pos]) && self::isDigit($input[$pos + 1])) {
                $pair = (int) substr($input, $pos, 2);
                return [[self::CW_ASCII_DIGIT_PAIR + $pair], 2, DataMatrixMode::ASCII];
            }
            $b = ord($input[$pos]);
            if ($b > 0x7F) {
                return [[self::CW_ASCII_EXTENDED_ASCII, $b - 128 + 1], 1, DataMatrixMode::ASCII];
            }
            return [[$b + 1], 1, DataMatrixMode::ASCII];
        }
        $rest = substr($input, $pos);
        $runLen = self::tripletRunLength($rest, $mode);
        $block = substr($rest, 0, $runLen);

        if ($mode === DataMatrixMode::BASE256) {
            // The latch is already emitted (it is codeword #$emittedCount, 1-based);
            // the length codeword follows at the next absolute symbol position.
            // Base256 auto-returns to ASCII after its length-prefixed byte run.
            return [self::encodeBase256Body($block, $emittedCount + 1), $runLen, DataMatrixMode::ASCII];
        }

        // C40 / TEXT. encodeC40/encodeText terminate with a 254 unlatch for a
        // residual of 0 or 2 (the run stays in triplet mode, walker defers the
        // unlatch), or with an ASCII codeword for a residual of 1 (the in-band
        // 254 already returned to ASCII). Detect which from the un-stripped form.
        $full = $mode === DataMatrixMode::C40
            ? self::encodeC40($block)
            : self::encodeText($block);
        $newMode = end($full) === 254 ? $mode : DataMatrixMode::ASCII;
        return [self::stripTripletWrapper($full), $runLen, $newMode];
    }

    /**
     * Strip the leading latch (230 or 239) and trailing unlatch (254) emitted
     * by encodeC40/encodeText: in the Annex P walker we emit the latch via
     * modeSwitch() and defer the unlatch until the next mode change or end of
     * input. Residual-1 fallback emits the unlatch followed by an ASCII
     * codeword for the trailing byte, so we only strip when the very last
     * codeword is 254.
     *
     * @param list<int> $full
     * @return list<int>
     */
    private static function stripTripletWrapper(array $full): array
    {
        array_shift($full);
        if (end($full) === 254) {
            array_pop($full);
        }
        // array_shift / array_pop on a list already reindex; return is still a list.
        return $full;
    }

    /**
     * Length of the prefix of $rest that is cheaper to encode in $mode than to
     * switch back to ASCII.
     */
    private static function tripletRunLength(string $rest, DataMatrixMode $mode): int
    {
        $len = strlen($rest);
        for ($i = 1; $i <= $len; $i++) {
            $tail = substr($rest, $i, 4); // 4-byte tail: enough to amortise one mode switch's overhead.
            if ($tail === '') {
                break;
            }
            $costContinue = self::modeCost($tail, $mode);
            $costSwitch = 1.0 + self::modeCost($tail, DataMatrixMode::ASCII);
            if ($mode === DataMatrixMode::C40 || $mode === DataMatrixMode::TEXT) {
                $costSwitch += 1.0;
            }
            if ($costSwitch < $costContinue) {
                return $i;
            }
        }
        return $len;
    }

    private static function isDigit(string $c): bool
    {
        return $c >= '0' && $c <= '9';
    }

    private static function shouldDeclareUtf8(string $input, int $len): bool
    {
        $hasNonAscii = false;
        for ($i = 0; $i < $len; $i++) {
            if (ord($input[$i]) > 0x7F) {
                $hasNonAscii = true;
                break;
            }
        }
        return $hasNonAscii && mb_check_encoding($input, 'UTF-8');
    }

    /**
     * Emit the Base256 length prefix + randomized data (no latch).
     *
     * Randomization (ISO 5.4.3) keys off the codeword's ABSOLUTE 1-based position
     * in the symbol's codeword stream, not a block-relative index. The decoder
     * un-randomizes the length at that same absolute position; an off-by-one
     * there yields a bogus length and overruns the symbol.
     *
     * @param int $lengthPosition Absolute 1-based position of the length codeword.
     * @return list<int>
     */
    private static function encodeBase256Body(string $bytes, int $lengthPosition): array
    {
        $len = strlen($bytes);
        $out = [];
        $pos = $lengthPosition;
        if ($len < 250) {
            $out[] = self::randomize255State($len, $pos);
            $pos++;
        } else {
            $out[] = self::randomize255State(intdiv($len, 250) + 249, $pos);
            $pos++;
            $out[] = self::randomize255State($len % 250, $pos);
            $pos++;
        }
        for ($i = 0; $i < $len; $i++) {
            $out[] = self::randomize255State(ord($bytes[$i]), $pos);
            $pos++;
        }
        return $out;
    }

    /**
     * Base256 randomization (ISO 5.4.3): output = (value + ((149 * pos) % 255) + 1) mod 256.
     */
    private static function randomize255State(int $value, int $position): int
    {
        $pseudoRandom = ((149 * $position) % 255) + 1;
        return ($value + $pseudoRandom) % 256;
    }

    /**
     * Map a single byte to its C40 representation (ISO/IEC 16022 Table 8).
     *
     * @return list<int>
     */
    private static function c40Values(int $b): array
    {
        if ($b === 0x20) {
            return [3]; // space
        }
        if ($b >= 0x30 && $b <= 0x39) {
            return [$b - 0x30 + 4]; // digits 4-13
        }
        if ($b >= 0x41 && $b <= 0x5A) {
            return [$b - 0x41 + 14]; // uppercase A-Z basic set 14-39
        }
        return self::shiftMapping($b, caseSwap: false);
    }

    /**
     * Map a single byte to its Text-mode representation (ISO/IEC 16022 Table 8).
     *
     * @return list<int>
     */
    private static function textValues(int $b): array
    {
        if ($b === 0x20) {
            return [3];
        }
        if ($b >= 0x30 && $b <= 0x39) {
            return [$b - 0x30 + 4];
        }
        if ($b >= 0x61 && $b <= 0x7A) {
            return [$b - 0x61 + 14]; // lowercase a-z basic set 14-39
        }
        return self::shiftMapping($b, caseSwap: true);
    }

    /**
     * Shared Shift 1 / Shift 2 / Shift 3 / upper-shift logic for C40 and Text.
     * Returns the value sequence for a byte that is NOT in the basic set of the
     * caller's mode.
     *
     * $caseSwap=false (C40): lowercase a-z mapped via Shift 3 values 1-26.
     * $caseSwap=true  (Text): uppercase A-Z mapped via Shift 3 values 1-26.
     *
     * @return list<int>
     */
    private static function shiftMapping(int $b, bool $caseSwap): array
    {
        if ($b <= 0x1F) {
            return [0, $b]; // Shift 1: control chars
        }
        if ($b >= 0x21 && $b <= 0x2F) {
            return [1, $b - 0x21]; // Shift 2: punctuation 0-14
        }
        if ($b >= 0x3A && $b <= 0x40) {
            return [1, $b - 0x3A + 15]; // Shift 2: ':' to '@' -> 15-21
        }
        if ($b >= 0x5B && $b <= 0x5F) {
            return [1, $b - 0x5B + 22]; // Shift 2: '[' to '_' -> 22-26
        }
        if ($b === 0x60) {
            return [2, 0]; // Shift 3: backtick
        }
        if (!$caseSwap && $b >= 0x61 && $b <= 0x7A) {
            return [2, $b - 0x61 + 1]; // C40: lowercase a-z via Shift 3 -> 1-26
        }
        if ($caseSwap && $b >= 0x41 && $b <= 0x5A) {
            return [2, $b - 0x41 + 1]; // Text: uppercase A-Z via Shift 3 -> 1-26
        }
        if ($b >= 0x7B && $b <= 0x7F) {
            return [2, $b - 0x7B + 27]; // Shift 3: '{' to DEL -> 27-31
        }
        // Upper-shift escape for bytes > 0x7F: Shift 2 value 30 then recursive inner mapping.
        $inner = $caseSwap
            ? self::textValues($b - 128)
            : self::c40Values($b - 128);
        return [1, 30, ...$inner];
    }

    /**
     * Encode a string in C40 mode: [230] + packed triplets + [254].
     *
     * @return list<int>
     */
    public static function encodeC40(string $input): array
    {
        return self::encodeTripletMode($input, self::CW_ASCII_LATCH_C40, self::c40Values(...));
    }

    /**
     * Encode a string in Text mode (same packing as C40, different alphabet).
     *
     * @return list<int>
     */
    public static function encodeText(string $input): array
    {
        return self::encodeTripletMode($input, self::CW_ASCII_LATCH_TEXT, self::textValues(...));
    }

    /**
     * Shared triplet packing for C40 and Text:
     *   V = 1600*a + 40*b + c + 1; emit high byte then low byte.
     *
     * If the residual is 0: emit unlatch (254).
     * If residual is 2: pad with value 0 to form a triplet, then unlatch.
     * If residual is 1: unlatch then re-emit the last input byte as ASCII (b + 1).
     *
     * @param callable(int): list<int> $mapByte
     * @return list<int>
     */
    private static function encodeTripletMode(string $input, int $latch, callable $mapByte): array
    {
        $out = [$latch];
        $values = [];
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            foreach ($mapByte(ord($input[$i])) as $v) {
                $values[] = $v;
            }
        }
        $i = 0;
        $n = count($values);
        while ($n - $i >= 3) {
            $packed = 1600 * $values[$i] + 40 * $values[$i + 1] + $values[$i + 2] + 1;
            $out[] = ($packed >> 8) & 0xFF;
            $out[] = $packed & 0xFF;
            $i += 3;
        }
        $residual = $n - $i;
        if ($residual === 0) {
            $out[] = 254;
        } elseif ($residual === 2) {
            $packed = 1600 * $values[$i] + 40 * $values[$i + 1] + 0 + 1;
            $out[] = ($packed >> 8) & 0xFF;
            $out[] = $packed & 0xFF;
            $out[] = 254;
        } else {
            // residual === 1: unlatch, then re-emit the last input byte as ASCII.
            $out[] = 254;
            $lastByte = ord($input[$len - 1]);
            $out[] = $lastByte + 1;
        }
        return $out;
    }
}

/**
 * DataMatrix ECC200 high-level encoder mode (ISO/IEC 16022 Table 6).
 *
 * Only the four modes in scope for this implementation are listed.
 * X12 and EDIFACT are intentionally out of scope (see design spec).
 *
 * @internal
 */
enum DataMatrixMode: string
{
    case ASCII   = 'ASCII';
    case C40     = 'C40';
    case TEXT    = 'TEXT';
    case BASE256 = 'BASE256';
}
