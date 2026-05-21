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
            [$emitted, $consumed] = self::encodeOneUnit($input, $i, $mode);
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
        $len = strlen($input);
        $window = min(8, $len - $pos);
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
            $switchCost += 1.0; // extra unlatch
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
            DataMatrixMode::C40     => self::tripletCost($sub, $len, self::c40Values(...)),
            DataMatrixMode::TEXT    => self::tripletCost($sub, $len, self::textValues(...)),
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
     * @return array{list<int>, int}
     */
    private static function encodeOneUnit(string $input, int $pos, DataMatrixMode $mode): array
    {
        $len = strlen($input);
        if ($mode === DataMatrixMode::ASCII) {
            if ($pos + 1 < $len && self::isDigit($input[$pos]) && self::isDigit($input[$pos + 1])) {
                $pair = (int) substr($input, $pos, 2);
                return [[self::CW_ASCII_DIGIT_PAIR + $pair], 2];
            }
            $b = ord($input[$pos]);
            if ($b > 0x7F) {
                return [[self::CW_ASCII_EXTENDED_ASCII, $b - 128 + 1], 1];
            }
            return [[$b + 1], 1];
        }
        $rest = substr($input, $pos);
        $runLen = self::tripletRunLength($rest, $mode);
        $block = substr($rest, 0, $runLen);
        // ASCII handled by the early return above; remaining modes are C40 / TEXT / BASE256.
        return match ($mode) {
            DataMatrixMode::C40  => [self::stripTripletWrapper(self::encodeC40($block)), $runLen],
            DataMatrixMode::TEXT => [self::stripTripletWrapper(self::encodeText($block)), $runLen],
            default              => [self::stripBase256Latch(self::encodeBase256($block)), $runLen],
        };
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
     * @param list<int> $full
     * @return list<int>
     */
    private static function stripBase256Latch(array $full): array
    {
        array_shift($full);
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
            $tail = substr($rest, $i, 4);
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

    /**
     * Encode a byte sequence using Base256 mode per ISO/IEC 16022 5.4.3.
     *
     * Output structure:
     *   [231]
     *   [length codeword(s)] (1 codeword if length < 250, else 2)
     *   [randomized data bytes...]
     *
     * Randomization formula (ISO 5.4.3): for codeword at position pos (1-based,
     * counting from the byte after the latch), output = (raw + ((149 * pos) % 255) + 1) mod 256.
     *
     * @return list<int>
     */
    public static function encodeBase256(string $bytes): array
    {
        $len = strlen($bytes);
        $out = [self::CW_ASCII_LATCH_BASE256];
        $pos = 1;
        if ($len < 250) {
            $out[] = self::randomize255State($len, $pos);
            $pos++;
        } else {
            $hi = intdiv($len, 250) + 249;
            $lo = $len % 250;
            $out[] = self::randomize255State($hi, $pos);
            $pos++;
            $out[] = self::randomize255State($lo, $pos);
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
     * Map a single byte to its C40 representation (ISO 16022 Table 8).
     *
     * Returns a list of values in the C40 alphabet to emit in order, including
     * any shift codewords needed:
     *   - Shift 1 (control chars 0x00-0x1F): emit [0, byte]
     *   - Shift 2 (punctuation 0x21-0x2F, ':' to '@', '[' to '_'): emit [1, mapped]
     *   - Shift 3 (lowercase a-z + extras): emit [2, mapped]
     *   - Basic set (space, digits, uppercase): emit single value
     *   - Bytes > 0x7F: emit upper-shift escape (Shift 2 value 30) then inner mapping for byte - 128.
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
            return [$b - 0x41 + 14]; // uppercase A-Z 14-39
        }
        if ($b <= 0x1F) {
            return [0, $b]; // Shift 1
        }
        if ($b <= 0x2F) {
            return [1, $b - 0x21]; // Shift 2 punctuation 0-14 (0x21-0x2F; 0x20 handled above)
        }
        if ($b <= 0x40) {
            return [1, $b - 0x3A + 15]; // Shift 2 ':' to '@' 15-21 (0x3A-0x40; 0x30-0x39 handled above)
        }
        if ($b <= 0x5F) {
            return [1, $b - 0x5B + 22]; // Shift 2 '[' to '_' 22-26 (0x5B-0x5F; 0x41-0x5A handled above)
        }
        if ($b === 0x60) {
            return [2, 0]; // backtick -> Shift 3 value 0
        }
        if ($b <= 0x7A) {
            return [2, $b - 0x61 + 1]; // Shift 3 lowercase a-z 1-26
        }
        if ($b <= 0x7F) {
            return [2, $b - 0x7B + 27]; // Shift 3 '{' to DEL 27-31
        }
        // Bytes > 0x7F: upper-shift escape (Shift 2 value 30), then inner mapping for b - 128.
        $inner = self::c40Values($b - 128);
        return array_merge([1, 30], $inner);
    }

    /**
     * Same mapping as C40 but with lowercase in the basic set and uppercase in Shift 3.
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
            return [$b - 0x61 + 14]; // lowercase basic set 14-39
        }
        if ($b <= 0x1F) {
            return [0, $b];
        }
        if ($b <= 0x2F) {
            return [1, $b - 0x21]; // Shift 2 punctuation 0-14 (0x21-0x2F; 0x20 handled above)
        }
        if ($b <= 0x40) {
            return [1, $b - 0x3A + 15]; // Shift 2 ':' to '@' (0x3A-0x40; digits handled above)
        }
        if ($b <= 0x5A) {
            return [2, $b - 0x41 + 1]; // uppercase Shift 3 1-26 (0x41-0x5A)
        }
        if ($b <= 0x5F) {
            return [1, $b - 0x5B + 22]; // Shift 2 '[' to '_' (0x5B-0x5F)
        }
        if ($b === 0x60) {
            return [2, 0];
        }
        if ($b <= 0x7F) {
            return [2, $b - 0x7B + 27]; // Shift 3 '{' to DEL (0x7B-0x7F; lowercase handled above)
        }
        $inner = self::textValues($b - 128);
        return array_merge([1, 30], $inner);
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
