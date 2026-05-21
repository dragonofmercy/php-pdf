<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Aztec high-level encoder over the five ASCII modes (UPPER, LOWER, MIXED,
 * PUNCT, DIGIT) per ISO/IEC 24778 Table 3.
 *
 * Implements a dynamic-programming, shortest-path encoding: for each prefix
 * of the input we keep a Pareto-optimal set of encoding "states" (each one
 * tracking the current mode, accumulated bit count and any pending binary
 * shift byte run); on the next character we generate every reachable next
 * state via latch / shift / binary-shift, prune dominated ones, and at the
 * end pick the state with the lowest bit count and emit its bit stream.
 *
 * The bit stream is returned as a string of '0' / '1' characters, the same
 * format the downstream LowLevelEncoder consumes.
 *
 * Adapted from zxing-java HighLevelEncoder.java + State.java (Apache 2.0).
 * Byte mode is internally supported by the state machine (used for any byte
 * that does not appear in any of the five ASCII tables). When the input
 * contains any byte > 0x7F, the encoder pre-pends an ECI FLG(n=26) escape
 * sequence announcing UTF-8 (per ISO/IEC 24778 sec.7.3.1.5), then lets the
 * existing DP route the non-ASCII bytes through BINARY SHIFT - byte-for-byte
 * identical to zxing's State.appendFLGn(26) emission.
 *
 * @phpstan-type State array{mode: int, token: EncoderToken|null, bsBytes: int, bitCount: int, bsCost: int}
 *
 * @internal
 */
final class HighLevelEncoder
{
    private const int MODE_UPPER = 0;
    private const int MODE_LOWER = 1;
    private const int MODE_DIGIT = 2;
    private const int MODE_MIXED = 3;
    private const int MODE_PUNCT = 4;

    /**
     * Latch transitions between the five modes. Each entry packs two 16-bit
     * fields: the high half is the bit count and the low half is the bit
     * pattern to emit (most significant bit first) in the source mode.
     *
     * Some transitions chain through an intermediate mode (e.g. UPPER -> PUNCT
     * goes via MIXED, costing 10 bits = M/L (5) + P/L (5)).
     *
     * @var list<list<int>>
     */
    private const array LATCH_TABLE = [
        // from UPPER
        [
            0,
            (5 << 16) + 28,               // UPPER -> LOWER
            (5 << 16) + 30,               // UPPER -> DIGIT
            (5 << 16) + 29,               // UPPER -> MIXED
            (10 << 16) + (29 << 5) + 30,  // UPPER -> MIXED -> PUNCT
        ],
        // from LOWER
        [
            (9 << 16) + (30 << 4) + 14,   // LOWER -> DIGIT -> UPPER
            0,
            (5 << 16) + 30,               // LOWER -> DIGIT
            (5 << 16) + 29,               // LOWER -> MIXED
            (10 << 16) + (29 << 5) + 30,  // LOWER -> MIXED -> PUNCT
        ],
        // from DIGIT (current codewords are 4 bits, the latch itself is 4 bits)
        [
            (4 << 16) + 14,                          // DIGIT -> UPPER
            (9 << 16) + (14 << 5) + 28,              // DIGIT -> UPPER -> LOWER
            0,
            (9 << 16) + (14 << 5) + 29,              // DIGIT -> UPPER -> MIXED
            (14 << 16) + (14 << 10) + (29 << 5) + 30, // DIGIT -> UPPER -> MIXED -> PUNCT
        ],
        // from MIXED
        [
            (5 << 16) + 29,               // MIXED -> UPPER
            (5 << 16) + 28,               // MIXED -> LOWER
            (10 << 16) + (29 << 5) + 30,  // MIXED -> UPPER -> DIGIT
            0,
            (5 << 16) + 30,               // MIXED -> PUNCT
        ],
        // from PUNCT
        [
            (5 << 16) + 31,               // PUNCT -> UPPER
            (10 << 16) + (31 << 5) + 28,  // PUNCT -> UPPER -> LOWER
            (10 << 16) + (31 << 5) + 30,  // PUNCT -> UPPER -> DIGIT
            (10 << 16) + (31 << 5) + 29,  // PUNCT -> UPPER -> MIXED
            0,
        ],
    ];

    /**
     * Shift codewords (one-character escapes). -1 means "no shift available
     * between these two modes". A shift emits the escape codeword in the
     * current mode, then the target character in the target mode, then falls
     * back to the original mode.
     *
     * @var list<list<int>>
     */
    private const array SHIFT_TABLE = [
        // from UPPER -> [UPPER, LOWER, DIGIT, MIXED, PUNCT]
        [-1, -1, -1, -1, 0],
        // from LOWER
        [28, -1, -1, -1, 0],
        // from DIGIT
        [15, -1, -1, -1, 0],
        // from MIXED
        [-1, -1, -1, -1, 0],
        // from PUNCT
        [-1, -1, -1, -1, -1],
    ];

    /**
     * Reverse CHARMAP, lazily computed once. Maps mode -> (byte code -> codeword + 1).
     * Storing codeword + 1 lets us distinguish "no mapping" (0) from "codeword 0".
     *
     * Note: PUNCT entry 0 is the FLG(n) escape and is intentionally not exposed
     * as a regular character mapping. Pair-mode handling uses it directly.
     *
     * @var array<int, array<int, int>>|null
     */
    private static ?array $charMap = null;

    /**
     * Encode an arbitrary byte string to an Aztec bit stream ('0' / '1' chars).
     *
     * Inputs containing only ASCII (bytes 0x00..0x7F) flow through the
     * dynamic-programming optimiser unchanged. Any byte > 0x7F triggers an
     * ECI FLG(n=26) prefix announcing UTF-8 to the decoder; the rest of the
     * input then rides on the same DP, which routes non-mappable bytes via
     * BINARY SHIFT (effectively Byte mode).
     */
    public static function encode(string $data): string
    {
        if ($data === '') {
            throw new PdfException('Aztec code data must not be empty');
        }

        $textLen = strlen($data);
        $initialState = self::makeState(null, self::MODE_UPPER, 0, 0);
        if (self::containsNonAscii($data)) {
            $initialState = self::appendFLGn($initialState, 26);
        }
        $states = [$initialState];

        for ($index = 0; $index < $textLen; $index++) {
            $byte = ord($data[$index]);
            $nextByte = $index + 1 < $textLen ? ord($data[$index + 1]) : 0;
            $pairCode = match (true) {
                $byte === 0x0D && $nextByte === 0x0A => 2, // CR LF
                $byte === 0x2E && $nextByte === 0x20 => 3, // ". "
                $byte === 0x2C && $nextByte === 0x20 => 4, // ", "
                $byte === 0x3A && $nextByte === 0x20 => 5, // ": "
                default                              => 0,
            };

            if ($pairCode > 0) {
                $states = self::updateStateListForPair($states, $index, $pairCode);
                $index++;
            } else {
                $states = self::updateStateListForChar($states, $index, $byte);
            }
        }

        // Pick the state with the smallest bit count.
        $best = $states[0];
        foreach ($states as $state) {
            if ($state['bitCount'] < $best['bitCount']) {
                $best = $state;
            }
        }

        return self::stateToBitString($best, $data);
    }

    // -----------------------------------------------------------------------
    // State helpers (state is a plain array, immutable by convention)
    // -----------------------------------------------------------------------

    /**
     * @return State
     */
    private static function makeState(?EncoderToken $token, int $mode, int $binaryBytes, int $bitCount): array
    {
        return [
            'mode'     => $mode,
            'token'    => $token,
            'bsBytes'  => $binaryBytes,
            'bitCount' => $bitCount,
            'bsCost'   => self::calculateBinaryShiftCost($binaryBytes),
        ];
    }

    private static function calculateBinaryShiftCost(int $binaryShiftByteCount): int
    {
        if ($binaryShiftByteCount > 62) {
            return 21;
        }
        if ($binaryShiftByteCount > 31) {
            return 20;
        }
        if ($binaryShiftByteCount > 0) {
            return 10;
        }
        return 0;
    }

    private static function containsNonAscii(string $data): bool
    {
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            if (ord($data[$i]) > 0x7F) {
                return true;
            }
        }
        return false;
    }

    /**
     * Emit an ECI FLG(n) escape (ISO/IEC 24778 sec.7.3.1.5) before the payload.
     *
     * Mirrors zxing-java State.appendFLGn(int eci) (Apache 2.0). The escape
     * is composed of a PUNCT shift, the FLG(n) trigger codeword (0 in PUNCT),
     * the digit count n in 3 bits (1..6), and the n ECI-designator digits as
     * DIGIT-mode codewords (digit d at codeword d + 2). For ECI=26 (UTF-8)
     * this produces 21 bits and leaves the state in UPPER mode with no
     * pending binary-shift run.
     *
     * @param State $state
     *
     * @return State
     */
    private static function appendFLGn(array $state, int $eci): array
    {
        if ($eci < 0 || $eci > 999999) {
            throw new PdfException('Aztec ECI code must be between 0 and 999999');
        }
        // Shift to PUNCT and emit codeword 0 (the FLG(n) trigger).
        $result = self::shiftAndAppend($state, self::MODE_PUNCT, 0);
        $token = $result['token'];
        $digits = (string) $eci;
        $digitCount = strlen($digits);
        // n in 3 bits (1..6 per ISO/IEC 24778 Table 4).
        $token = EncoderToken::simple($token, $digitCount, 3);
        $bitsAdded = 3;
        for ($i = 0; $i < $digitCount; $i++) {
            // DIGIT-mode codeword for ASCII digit d is (d - '0') + 2.
            $cw = (ord($digits[$i]) - 0x30) + 2;
            $token = EncoderToken::simple($token, $cw, 4);
            $bitsAdded += 4;
        }
        return self::makeState($token, $state['mode'], 0, $result['bitCount'] + $bitsAdded);
    }

    /**
     * Append a latch + new codeword to a state, returning a new state.
     *
     * @param State $state
     *
     * @return State
     */
    private static function latchAndAppend(array $state, int $targetMode, int $value): array
    {
        $token = $state['token'];
        $bitCount = $state['bitCount'];
        if ($targetMode !== $state['mode']) {
            $latch = self::LATCH_TABLE[$state['mode']][$targetMode];
            $latchBits = $latch >> 16;
            $latchValue = $latch & 0xFFFF;
            $token = EncoderToken::simple($token, $latchValue, $latchBits);
            $bitCount += $latchBits;
        }
        $codewordBits = $targetMode === self::MODE_DIGIT ? 4 : 5;
        $token = EncoderToken::simple($token, $value, $codewordBits);
        return self::makeState($token, $targetMode, 0, $bitCount + $codewordBits);
    }

    /**
     * Shift to a different mode for a single codeword, then return to the
     * current mode.
     *
     * @param State $state
     *
     * @return State
     */
    private static function shiftAndAppend(array $state, int $targetMode, int $value): array
    {
        $thisModeBits = $state['mode'] === self::MODE_DIGIT ? 4 : 5;
        $shiftValue = self::SHIFT_TABLE[$state['mode']][$targetMode];
        $token = EncoderToken::simple($state['token'], $shiftValue, $thisModeBits);
        // Shifts only target UPPER / PUNCT - both 5-bit modes.
        $token = EncoderToken::simple($token, $value, 5);
        return self::makeState($token, $state['mode'], 0, $state['bitCount'] + $thisModeBits + 5);
    }

    /**
     * Add a single byte to the binary shift accumulator. PUNCT/DIGIT must latch
     * back to UPPER first (binary shift is not reachable from those modes).
     *
     * @param State $state
     *
     * @return State
     */
    private static function addBinaryShiftChar(array $state, int $index): array
    {
        $token = $state['token'];
        $mode = $state['mode'];
        $bitCount = $state['bitCount'];
        if ($mode === self::MODE_PUNCT || $mode === self::MODE_DIGIT) {
            $latch = self::LATCH_TABLE[$mode][self::MODE_UPPER];
            $latchBits = $latch >> 16;
            $latchValue = $latch & 0xFFFF;
            $token = EncoderToken::simple($token, $latchValue, $latchBits);
            $bitCount += $latchBits;
            $mode = self::MODE_UPPER;
        }
        $bsBytes = $state['bsBytes'];
        $delta = match (true) {
            $bsBytes === 0 || $bsBytes === 31 => 18,
            $bsBytes === 62                   => 9,
            default                           => 8,
        };
        $result = self::makeState($token, $mode, $bsBytes + 1, $bitCount + $delta);
        if ($result['bsBytes'] === 2047 + 31) {
            $result = self::endBinaryShift($result, $index + 1);
        }
        return $result;
    }

    /**
     * Flush any pending binary-shift byte run as a single BINARY token.
     *
     * @param State $state
     *
     * @return State
     */
    private static function endBinaryShift(array $state, int $index): array
    {
        if ($state['bsBytes'] === 0) {
            return $state;
        }
        $token = EncoderToken::binary($state['token'], $index - $state['bsBytes'], $state['bsBytes']);
        return self::makeState($token, $state['mode'], 0, $state['bitCount']);
    }

    /**
     * Pareto dominance: true if $a is strictly no worse than $b in every
     * future scenario. Used to prune dominated states.
     *
     * @param State $a
     * @param State $b
     */
    private static function isBetterThanOrEqualTo(array $a, array $b): bool
    {
        $newBits = $a['bitCount'] + (self::LATCH_TABLE[$a['mode']][$b['mode']] >> 16);
        if ($a['bsBytes'] < $b['bsBytes']) {
            $newBits += $b['bsCost'] - $a['bsCost'];
        } elseif ($a['bsBytes'] > $b['bsBytes'] && $b['bsBytes'] > 0) {
            $newBits += 10;
        }
        return $newBits <= $b['bitCount'];
    }

    // -----------------------------------------------------------------------
    // Transition functions
    // -----------------------------------------------------------------------

    /**
     * @param list<State> $states
     *
     * @return list<State>
     */
    private static function updateStateListForChar(array $states, int $index, int $byte): array
    {
        $result = [];
        foreach ($states as $state) {
            self::updateStateForChar($state, $index, $byte, $result);
        }
        return self::simplifyStates($result);
    }

    /**
     * @param State $state
     * @param list<State> $result
     */
    private static function updateStateForChar(array $state, int $index, int $byte, array &$result): void
    {
        $charMap = self::charMap();
        $charInCurrentTable = ($charMap[$state['mode']][$byte] ?? 0) > 0;
        $stateNoBinary = null;

        for ($mode = self::MODE_UPPER; $mode <= self::MODE_PUNCT; $mode++) {
            $charInMode = $charMap[$mode][$byte] ?? 0;
            if ($charInMode > 0) {
                if ($stateNoBinary === null) {
                    $stateNoBinary = self::endBinaryShift($state, $index);
                }
                // Codeword value is the stored CHARMAP entry minus 1 (we
                // stored codeword+1 to differentiate "no mapping" from
                // "codeword 0").
                $cw = $charInMode - 1;
                // Try latching to its mode.
                if (!$charInCurrentTable || $mode === $state['mode'] || $mode === self::MODE_DIGIT) {
                    $result[] = self::latchAndAppend($stateNoBinary, $mode, $cw);
                }
                // Try shifting to its mode (only if the char isn't in the
                // current mode - shifting otherwise wastes bits).
                if (!$charInCurrentTable && self::SHIFT_TABLE[$state['mode']][$mode] >= 0) {
                    $result[] = self::shiftAndAppend($stateNoBinary, $mode, $cw);
                }
            }
        }

        if ($state['bsBytes'] > 0 || ($charMap[$state['mode']][$byte] ?? 0) === 0) {
            $result[] = self::addBinaryShiftChar($state, $index);
        }
    }

    /**
     * @param list<State> $states
     *
     * @return list<State>
     */
    private static function updateStateListForPair(array $states, int $index, int $pairCode): array
    {
        $result = [];
        foreach ($states as $state) {
            self::updateStateForPair($state, $index, $pairCode, $result);
        }
        return self::simplifyStates($result);
    }

    /**
     * @param State $state
     * @param list<State> $result
     */
    private static function updateStateForPair(array $state, int $index, int $pairCode, array &$result): void
    {
        $stateNoBinary = self::endBinaryShift($state, $index);
        // 1. Latch to PUNCT and emit the pair codeword.
        $result[] = self::latchAndAppend($stateNoBinary, self::MODE_PUNCT, $pairCode);
        if ($state['mode'] !== self::MODE_PUNCT) {
            // 2. Shift to PUNCT and emit the pair codeword.
            $result[] = self::shiftAndAppend($stateNoBinary, self::MODE_PUNCT, $pairCode);
        }
        if ($pairCode === 3 || $pairCode === 4) {
            // ". " and ", " both have a representation in DIGIT (period = 13,
            // comma = 12) followed by space (codeword 1). 16 - pairCode = 13 or 12.
            $digitState = self::latchAndAppend($stateNoBinary, self::MODE_DIGIT, 16 - $pairCode);
            $digitState = self::latchAndAppend($digitState, self::MODE_DIGIT, 1);
            $result[] = $digitState;
        }
        if ($state['bsBytes'] > 0) {
            $bs1 = self::addBinaryShiftChar($state, $index);
            $bs2 = self::addBinaryShiftChar($bs1, $index + 1);
            $result[] = $bs2;
        }
    }

    /**
     * Drop dominated states. A state is dominated when another state is
     * "better than or equal to" it under all future scenarios.
     *
     * @param list<State> $states
     *
     * @return list<State>
     */
    private static function simplifyStates(array $states): array
    {
        /** @var list<State> $result */
        $result = [];
        foreach ($states as $newState) {
            $add = true;
            $pruned = [];
            $resultCount = count($result);
            for ($i = 0; $i < $resultCount; $i++) {
                $oldState = $result[$i];
                if (self::isBetterThanOrEqualTo($oldState, $newState)) {
                    // Old state dominates new: drop new, keep all remaining
                    // old states as-is, and stop checking.
                    $add = false;
                    for (; $i < $resultCount; $i++) {
                        $pruned[] = $result[$i];
                    }
                    break;
                }
                if (self::isBetterThanOrEqualTo($newState, $oldState)) {
                    // New state dominates this old one: drop the old one.
                    continue;
                }
                $pruned[] = $oldState;
            }
            $result = $pruned;
            if ($add) {
                // Prepend (zxing uses addFirst on a Deque). Order is not
                // semantically meaningful but matches zxing's traversal.
                array_unshift($result, $newState);
            }
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Token rendering
    // -----------------------------------------------------------------------

    /**
     * Render the chosen state's token chain to a bit string.
     *
     * @param State $state
     */
    private static function stateToBitString(array $state, string $text): string
    {
        $finalState = self::endBinaryShift($state, strlen($text));

        // Walk the linked list from the latest token back to the first,
        // collecting tokens.
        /** @var list<EncoderToken> $tokens */
        $tokens = [];
        for ($t = $finalState['token']; $t !== null; $t = $t->prev) {
            $tokens[] = $t;
        }

        $out = '';
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($token->type === EncoderToken::TYPE_SIMPLE) {
                $out .= self::bitsString($token->a, $token->b);
            } else {
                $out .= self::renderBinaryToken($token->a, $token->b, $text);
            }
        }

        return $out;
    }

    /**
     * Render a binary-shift token to its on-wire bit string. This emits the
     * BINARY SHIFT codeword (31), the length prefix (5 or 16 bits), and the
     * data bytes (with a second 5+5 header at position 31 for runs of 32..62).
     */
    private static function renderBinaryToken(int $start, int $count, string $text): string
    {
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            if ($i === 0 || ($i === 31 && $count <= 62)) {
                $out .= self::bitsString(31, 5); // BINARY SHIFT codeword
                if ($count > 62) {
                    $out .= self::bitsString($count - 31, 16);
                } elseif ($i === 0) {
                    $out .= self::bitsString(min($count, 31), 5);
                } else {
                    // 32 <= count <= 62, second header at i == 31
                    $out .= self::bitsString($count - 31, 5);
                }
            }
            $out .= self::bitsString(ord($text[$start + $i]), 8);
        }
        return $out;
    }

    /** Convert ($value, $bits) to a $bits-long binary string, MSB first. */
    private static function bitsString(int $value, int $bits): string
    {
        return str_pad(decbin($value & ((1 << $bits) - 1)), $bits, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------------
    // CHARMAP construction (lazy, once per process)
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array<int, int>>
     */
    private static function charMap(): array
    {
        if (self::$charMap !== null) {
            return self::$charMap;
        }
        /** @var array<int, array<int, int>> $map */
        $map = [
            self::MODE_UPPER => [],
            self::MODE_LOWER => [],
            self::MODE_DIGIT => [],
            self::MODE_MIXED => [],
            self::MODE_PUNCT => [],
        ];

        // UPPER: space + A..Z (codewords 1..27 -> stored as 2..28)
        $map[self::MODE_UPPER][0x20] = 1 + 1; // codeword 1, stored as 2
        for ($c = 0x41; $c <= 0x5A; $c++) {
            $map[self::MODE_UPPER][$c] = ($c - 0x41 + 2) + 1;
        }

        // LOWER: space + a..z
        $map[self::MODE_LOWER][0x20] = 1 + 1;
        for ($c = 0x61; $c <= 0x7A; $c++) {
            $map[self::MODE_LOWER][$c] = ($c - 0x61 + 2) + 1;
        }

        // DIGIT: space + 0..9 + ',' + '.'
        $map[self::MODE_DIGIT][0x20] = 1 + 1;
        for ($c = 0x30; $c <= 0x39; $c++) {
            $map[self::MODE_DIGIT][$c] = ($c - 0x30 + 2) + 1;
        }
        $map[self::MODE_DIGIT][0x2C] = 12 + 1; // ','
        $map[self::MODE_DIGIT][0x2E] = 13 + 1; // '.'

        // MIXED: \0, space, ctrl chars, then @ \ ^ _ ` | ~ DEL
        $mixedTable = [
            0x00, 0x20, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0A,
            0x0B, 0x0C, 0x0D, 0x1B, 0x1C, 0x1D, 0x1E, 0x1F, 0x40, 0x5C, 0x5E,
            0x5F, 0x60, 0x7C, 0x7E, 0x7F,
        ];
        foreach ($mixedTable as $i => $byte) {
            $map[self::MODE_MIXED][$byte] = $i + 1;
        }

        // PUNCT: codewords 0..30; entry 0 is the FLG(n) escape (no char map).
        // The duplicate apostrophe (0x27 at indexes 7 and 12) intentionally
        // mirrors zxing-java: the last assignment wins (codeword 12).
        $punctTable = [
            null, 0x0D, null, null, null, null, 0x21, 0x27, 0x23, 0x24, 0x25, 0x26,
            0x27, 0x28, 0x29, 0x2A, 0x2B, 0x2C, 0x2D, 0x2E, 0x2F, 0x3A, 0x3B, 0x3C,
            0x3D, 0x3E, 0x3F, 0x5B, 0x5D, 0x7B, 0x7D,
        ];
        foreach ($punctTable as $i => $byte) {
            if ($byte !== null) {
                $map[self::MODE_PUNCT][$byte] = $i + 1;
            }
        }

        self::$charMap = $map;
        return $map;
    }
}
