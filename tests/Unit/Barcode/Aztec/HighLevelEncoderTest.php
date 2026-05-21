<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\Aztec\HighLevelEncoder;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Aztec ASCII high-level encoder.
 *
 * Expected bit strings derived from ISO/IEC 24778 Table 3 character codes:
 *   UPPER codeword 'A'=2, 'B'=3, 'C'=4, 'D'=5, ... 'Z'=27 (5 bits each)
 *   LOWER codeword 'a'=2, 'b'=3, 'c'=4, ... (5 bits each)
 *   DIGIT codeword '0'=2, '1'=3, '2'=4, '3'=5, ... '9'=11 (4 bits each)
 *   Mode latches (5-bit values appended in the source mode):
 *     UPPER -> LOWER: 28 = 11100
 *     UPPER -> MIXED: 29 = 11101
 *     UPPER -> DIGIT: 30 = 11110
 *     LOWER -> UPPER: via DIGIT (30) then DIGIT->UPPER (14, 4 bits)
 *     DIGIT -> UPPER: 14 = 1110 (4 bits, DIGIT codewords are 4-bit)
 */
final class HighLevelEncoderTest extends TestCase
{
    public function testEmptyInputThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Aztec code data must not be empty');
        HighLevelEncoder::encode('');
    }

    public function testSingleUppercaseLetter(): void
    {
        // 'A' -> UPPER codeword 2 = '00010' (5 bits)
        self::assertSame('00010', HighLevelEncoder::encode('A'));
    }

    public function testThreeUppercaseLetters(): void
    {
        // 'A'=2, 'B'=3, 'C'=4 -> '00010' '00011' '00100' = 15 bits
        $bits = HighLevelEncoder::encode('ABC');
        self::assertSame(15, strlen($bits));
        self::assertSame('000100001100100', $bits);
    }

    public function testLowercaseLatch(): void
    {
        // UPPER -> LOWER latch (28 = '11100') then a=2, b=3, c=4 in LOWER
        // 5 + 5 + 5 + 5 = 20 bits, '11100' '00010' '00011' '00100'
        $bits = HighLevelEncoder::encode('abc');
        self::assertSame(20, strlen($bits));
        self::assertSame('11100000100001100100', $bits);
    }

    public function testDigitLatch(): void
    {
        // UPPER -> DIGIT latch (30 = '11110') then '1'=3, '2'=4, '3'=5 in DIGIT
        // 5 + 4 + 4 + 4 = 17 bits, '11110' '0011' '0100' '0101'
        $bits = HighLevelEncoder::encode('123');
        self::assertSame(17, strlen($bits));
        self::assertSame('11110001101000101', $bits);
    }

    public function testMixedUpperAndDigit(): void
    {
        // 'A','B' in UPPER (10 bits), UPPER->DIGIT latch (5 bits),
        // '1','2','3' in DIGIT (12 bits), DIGIT->UPPER latch (4 bits = codeword 14),
        // 'C','D' in UPPER (10 bits) = 41 bits total.
        $bits = HighLevelEncoder::encode('AB123CD');
        self::assertSame(41, strlen($bits));
    }

    public function testSingleLowercaseUsesShiftWhenCheaper(): void
    {
        // A single lowercase letter after UPPER: the algorithm should pick the
        // cheapest path. A latch L/L (5) + char (5) = 10 bits, or no shift exists
        // from UPPER to LOWER (SHIFT_TABLE[UPPER][LOWER] = -1), so latch is forced.
        // Verify the encoder emits 10 bits.
        $bits = HighLevelEncoder::encode('a');
        self::assertSame(10, strlen($bits));
    }

    public function testUpperShiftFromLowerForSingleCapital(): void
    {
        // 'aBc': L/L latch (5), 'a' (5), U/S shift from LOWER to UPPER (5),
        // 'B' (5), 'c' (5) = 25 bits. Shifts back automatically.
        $bits = HighLevelEncoder::encode('aBc');
        self::assertSame(25, strlen($bits));
    }

    public function testTenDigitsBeatsUpperLatchPath(): void
    {
        // '1234567890' in DIGIT after one D/L latch:
        // 5 (latch) + 10 * 4 = 45 bits
        $bits = HighLevelEncoder::encode('1234567890');
        self::assertSame(45, strlen($bits));
    }

    public function testSpaceInUpperMode(): void
    {
        // 'A B' -> 'A'=2, space=1, 'B'=3 all in UPPER = 15 bits
        $bits = HighLevelEncoder::encode('A B');
        self::assertSame(15, strlen($bits));
        self::assertSame('000100000100011', $bits);
    }

    public function testMixedModeForControlChars(): void
    {
        // "A\t" -> 'A' in UPPER (5), MIXED latch (5), tab = codeword 10 in MIXED (5)
        // = 15 bits minimum (or via byte mode, but byte mode is not implemented in
        // this task and would cost more anyway: 5 + 5 + 8 = 18). Verify <= 15.
        $bits = HighLevelEncoder::encode("A\t");
        self::assertLessThanOrEqual(15, strlen($bits));
    }

    public function testPunctPairCarriageReturnNewline(): void
    {
        // "\r\n" -> PUNCT pair shortcut (pairCode 2). From UPPER: shift to PUNCT
        // (5 bits = codeword 0) then pair value 2 in PUNCT (5 bits) = 10 bits.
        // Alternative: latch to PUNCT via UPPER->MIXED->PUNCT (10) + pair (5) = 15.
        // Shift wins: 10 bits.
        $bits = HighLevelEncoder::encode("\r\n");
        self::assertSame(10, strlen($bits));
    }

    public function testReturnedStringIsOnlyZerosAndOnes(): void
    {
        $bits = HighLevelEncoder::encode('Hello World');
        self::assertMatchesRegularExpression('/^[01]+$/', $bits);
    }

    public function testNonAsciiTriggersByteModeWithEciUtf8(): void
    {
        // 'cafe' + U+00E9 (e-acute as UTF-8 0xc3 0xa9) forces non-ASCII path.
        $bits = HighLevelEncoder::encode('cafe' . "\xc3\xa9");
        $asciiOnly = HighLevelEncoder::encode('cafe');
        self::assertGreaterThan(strlen($asciiOnly), strlen($bits));
    }

    public function testPureBytePayload(): void
    {
        $bytes = "\xff\xfe\x80\x81";
        $bits = HighLevelEncoder::encode($bytes);
        self::assertGreaterThanOrEqual(32, strlen($bits));
    }

    public function testEciFlgUtf8PrefixBitPattern(): void
    {
        // Single non-ASCII byte 0x80. Expected emission:
        //   FLG(n=26) prefix (21 bits):
        //     SHIFT to PUNCT (5 bits, codeword 0)  = '00000'
        //     FLG(n) trigger in PUNCT (5 bits, codeword 0) = '00000'
        //     n = 2 in 3 bits = '010'
        //     DIGIT codeword for '2' (= 4) in 4 bits = '0100'
        //     DIGIT codeword for '6' (= 8) in 4 bits = '1000'
        //   Binary shift run (18 bits):
        //     BS_SHIFT codeword 31 in 5 bits = '11111'
        //     Length 1 in 5 bits = '00001'
        //     Byte 0x80 in 8 bits = '10000000'
        // Total: 21 + 18 = 39 bits.
        $bits = HighLevelEncoder::encode("\x80");
        self::assertSame(39, strlen($bits));
        self::assertSame('000000000001001001000' . '1111100001' . '10000000', $bits);
    }

    public function testNonAsciiByteCountInBinaryShift(): void
    {
        // Two non-ASCII bytes: prefix (21) + BS_SHIFT(5) + length=2(5) + 2*8 = 47 bits.
        $bits = HighLevelEncoder::encode("\xc3\xa9");
        self::assertSame(47, strlen($bits));
    }

    public function testPureAsciiUnaffectedByEciPath(): void
    {
        // Regression guard: pure-ASCII payload must NOT emit the ECI prefix.
        // 'A' alone is 5 bits; 21+ would indicate a spurious prefix.
        self::assertSame(5, strlen(HighLevelEncoder::encode('A')));
    }

    public function testMixedAsciiAndNonAsciiEmitsValidBitstring(): void
    {
        // Smoke test: mixed payload encodes to a non-empty bit string of 0/1.
        $bits = HighLevelEncoder::encode('Hello, ' . "\xe2\x9c\x93" . ' World!');
        self::assertMatchesRegularExpression('/^[01]+$/', $bits);
        self::assertGreaterThan(0, strlen($bits));
    }
}
