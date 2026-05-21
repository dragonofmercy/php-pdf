<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\DataMatrix;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix\HighLevelEncoder;
use PHPUnit\Framework\TestCase;

final class HighLevelEncoderTest extends TestCase
{
    public function testAsciiSingleByteIsValuePlusOne(): void
    {
        // ISO 16022 5.2.3: ASCII char c (0-127) -> codeword c + 1.
        // 'A' = 0x41 = 65 -> 66.
        self::assertSame([66], HighLevelEncoder::encode('A'));
        self::assertSame([66, 67, 68], HighLevelEncoder::encode('ABC'));
    }

    public function testAsciiDigitPairIs130PlusValue(): void
    {
        // Two consecutive digits "dd" pack into one codeword: 130 + (dd as integer).
        // "12" -> 130 + 12 = 142.
        self::assertSame([142], HighLevelEncoder::encode('12'));
        // "123456" -> three pairs: 130+12=142, 130+34=164, 130+56=186.
        self::assertSame([142, 164, 186], HighLevelEncoder::encode('123456'));
    }

    public function testAsciiOddDigitsFallsBackToSingleAscii(): void
    {
        // "1" alone: '1' = 0x31 = 49 -> 50.
        self::assertSame([50], HighLevelEncoder::encode('1'));
        // "12345" -> 142 (pair 12), 164 (pair 34), then '5' alone -> 53 + 1 = 54.
        self::assertSame([142, 164, 54], HighLevelEncoder::encode('12345'));
    }

    public function testAsciiTrailingDigitBeforeNonDigit(): void
    {
        // "1A" -> '1' alone (50), 'A' (66). Pair window only consumes consecutive digits.
        self::assertSame([50, 66], HighLevelEncoder::encode('1A'));
    }

    public function testBase256WrapsBinaryWithLengthPrefixAndRandomization(): void
    {
        // 3 binary bytes 0xFF, 0xFE, 0xFD wrapped by Base256:
        //   codeword 231 (latch to Base256), length codeword, then 3 randomized bytes.
        $out = HighLevelEncoder::encodeBase256("\xFF\xFE\xFD");
        self::assertCount(5, $out);
        self::assertSame(231, $out[0]);
        // For length < 250, the length codeword is the count, randomized at pos 1:
        // pseudoRandom = ((149 * 1) % 255) + 1 = 150; (3 + 150) % 256 = 153.
        self::assertSame(153, $out[1]);
    }

    public function testBase256SingleByteUsesLengthOne(): void
    {
        $out = HighLevelEncoder::encodeBase256("\x80");
        self::assertSame(231, $out[0]);
        // length 1 randomized at pos 1: (1 + 150) % 256 = 151.
        self::assertSame(151, $out[1]);
        self::assertCount(3, $out);
    }

    public function testBase256LongPayloadUsesTwoByteLength(): void
    {
        // Length >= 250 uses two length codewords:
        //   first = (length / 250) + 249  (randomized)
        //   second = length % 250         (randomized)
        $payload = str_repeat("\xFF", 300);
        $out = HighLevelEncoder::encodeBase256($payload);
        self::assertSame(231, $out[0]);
        // 1 (latch) + 2 (length) + 300 (data) = 303 codewords.
        self::assertCount(303, $out);
    }

    public function testC40EncodesThreeCharsIntoTwoCodewords(): void
    {
        // C40 packs 3 characters into a 16-bit value (2 codewords).
        // Formula: V = 1600*a + 40*b + c + 1, then high = V >> 8, low = V & 0xFF.
        // 'A' 'B' 'C' in C40 are 14, 15, 16 (uppercase basic set, offset 14 from 'A').
        // V = 1600*14 + 40*15 + 16 + 1 = 22400 + 600 + 17 = 23017
        // high = 89, low = 233
        $out = HighLevelEncoder::encodeC40('ABC');
        // Output: latch (230), high, low, unlatch (254).
        self::assertSame([230, 89, 233, 254], $out);
    }

    public function testC40DigitsUseBasicSet(): void
    {
        // Digits '0'..'9' in C40 are 4..13.
        // '123' -> V = 1600*5 + 40*6 + 7 + 1 = 8000 + 240 + 8 = 8248
        // high = 32, low = 56
        $out = HighLevelEncoder::encodeC40('123');
        self::assertSame([230, 32, 56, 254], $out);
    }

    public function testTextEncodesLowercaseInBasicSet(): void
    {
        // Text mode is C40 with lowercase in the basic set and uppercase in Shift 3.
        // 'abc' in Text basic set are 14, 15, 16 (same offsets as C40 uppercase).
        // V = 1600*14 + 40*15 + 16 + 1 = 23017, same packing as testC40EncodesThreeCharsIntoTwoCodewords.
        $out = HighLevelEncoder::encodeText('abc');
        self::assertSame([239, 89, 233, 254], $out);
    }
}
