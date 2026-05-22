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

    public function testRawBinaryRunLatchesToBase256(): void
    {
        // Production path: a run of 3+ contiguous high bytes latches to Base256.
        //   codeword 231 (latch), length codeword, then 3 randomized bytes.
        $out = HighLevelEncoder::encode("\xFF\xFE\xFD");
        self::assertCount(5, $out);
        self::assertSame(231, $out[0]);
        // The latch sits at symbol position 1, so the length codeword is randomized
        // at its ABSOLUTE position 2 (ISO 16022 5.4.3): ((149*2)%255)+1 = 44, (3+44)%256 = 47.
        self::assertSame(47, $out[1]);
    }

    public function testSingleHighByteUsesAsciiUpperShiftNotBase256(): void
    {
        // A lone high byte is cheaper as an ASCII upper-shift (235) than a Base256
        // segment, so the walker must NOT latch to Base256 for it.
        self::assertSame([235, 1], HighLevelEncoder::encode("\x80"));
    }

    public function testBase256BodyLengthPrefixIsRandomizedAtAbsolutePosition(): void
    {
        // encodeBase256Body emits [length codeword(s)] + randomized data, keyed off
        // the absolute 1-based position passed in (no latch).
        $body = self::callBase256Body("\xFF\xFE\xFD", 2);
        self::assertCount(4, $body); // 1 length + 3 data
        self::assertSame(47, $body[0]); // length 3 at position 2
    }

    public function testBase256BodyLongPayloadUsesTwoByteLength(): void
    {
        // Length >= 250 uses two length codewords, then the data.
        $body = self::callBase256Body(str_repeat("\xFF", 300), 2);
        self::assertCount(302, $body); // 2 length + 300 data
    }

    /**
     * Invoke the private Base256 body encoder (the real production helper).
     *
     * @return list<int>
     */
    private static function callBase256Body(string $bytes, int $lengthPosition): array
    {
        $method = new \ReflectionMethod(HighLevelEncoder::class, 'encodeBase256Body');
        /** @var list<int> $out */
        $out = $method->invoke(null, $bytes, $lengthPosition);
        return $out;
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

    public function testC40ResidualOneFallsBackToAscii(): void
    {
        // 'ABCD' -> 4 C40 values (14, 15, 16, 17). One triplet (ABC = 23017) packs to high=89, low=233.
        // residual = 1 (just 'D' left).
        // ISO 16022 5.2.5.2 residual 1: emit unlatch (254), then last input byte as ASCII.
        // 'D' = 0x44 = 68 -> ASCII codeword 69.
        // Final: [230 (latch), 89, 233, 254 (unlatch), 69 (ASCII 'D')].
        $out = HighLevelEncoder::encodeC40('ABCD');
        self::assertSame([230, 89, 233, 254, 69], $out);
    }

    public function testC40ResidualTwoPadsWithZero(): void
    {
        // 'ABCDE' -> 5 C40 values (14, 15, 16, 17, 18). One triplet (ABC) + residual 'DE' (17, 18).
        // residual = 2: pad with C40 value 0 -> triplet (D, E, pad) = (17, 18, 0).
        // V_first  = 1600*14 + 40*15 + 16 + 1 = 23017 -> high=89, low=233
        // V_second = 1600*17 + 40*18 + 0  + 1 = 27200 + 720 + 1 = 27921 -> high=109, low=17 (27921 >> 8 = 109, 27921 & 0xFF = 17)
        // Final: [230, 89, 233, 109, 17, 254].
        $out = HighLevelEncoder::encodeC40('ABCDE');
        self::assertSame([230, 89, 233, 109, 17, 254], $out);
    }

    public function testShortPureAsciiStaysAscii(): void
    {
        // Short ASCII payloads stay in ASCII mode (C40 latch overhead doesn't pay).
        self::assertSame([66, 67, 68], HighLevelEncoder::encode('ABC'));
    }

    public function testLongUppercaseRunSwitchesToC40(): void
    {
        // 20 consecutive uppercase letters: C40 wins. Output must start with 230 (latch).
        $out = HighLevelEncoder::encode(str_repeat('A', 20));
        self::assertSame(230, $out[0], 'Should latch to C40 for long uppercase run');
    }

    public function testLongLowercaseRunSwitchesToText(): void
    {
        $out = HighLevelEncoder::encode(str_repeat('a', 20));
        self::assertSame(239, $out[0], 'Should latch to Text for long lowercase run');
    }

    public function testHighByteRunTriggersBase256(): void
    {
        // A payload of 4 consecutive high bytes is cheaper in Base256 than the
        // 2-codeword extended-ASCII escape per byte (4 * 2 = 8 ASCII vs ~6 Base256).
        $out = HighLevelEncoder::encode("\xFF\xFE\xFD\xFC");
        self::assertSame(231, $out[0], 'Should latch to Base256 for high-byte run');
    }

    public function testUtf8InputUsesBase256ForHighRun(): void
    {
        // 'eee' with 3 consecutive U+00E9 (e-acute) = 6 contiguous high bytes
        // in UTF-8, well above the Base256 break-even of ~3 contiguous high bytes.
        $out = HighLevelEncoder::encode("\xC3\xA9\xC3\xA9\xC3\xA9");
        self::assertContains(231, $out, 'Should include Base256 latch for contiguous high-byte UTF-8 run');
    }

    public function testTextResidualOneReturnsToAsciiWithoutSpuriousUnlatch(): void
    {
        // 'Hello': 'H' as ASCII (73). Annex P latches to Text for 'ello'.
        // 'ell' packs as a triplet (values 18,25,25 -> 116,130); residual 1 emits
        // the in-band unlatch (254) then 'o' as ASCII (112). After the in-band
        // unlatch the encoder is back in ASCII, so NO closing 254 must follow.
        self::assertSame([73, 239, 116, 130, 254, 112], HighLevelEncoder::encode('Hello'));
    }

    public function testTextModeAllLowercaseDoesNotEndWithSpuriousUnlatch(): void
    {
        // All-lowercase payload latches to Text; the trailing residual-1 byte
        // returns to ASCII. The stream must end on that ASCII byte, never on a
        // bare 254 (which is invalid in ASCII mode and breaks decoders).
        $out = HighLevelEncoder::encode('the quick brown fox jumps over the lazy dog');
        self::assertNotSame(254, end($out), 'Text residual-1 must not leave a spurious closing unlatch');
    }

    public function testNonAsciiPayloadEmitsUtf8EciPrefix(): void
    {
        // Any non-ASCII byte makes the encoder declare UTF-8 via ECI 26 so readers
        // do not fall back to Latin-1 (which renders accents as mojibake).
        // ISO 16022 5.6.1: codeword 241 then (ECI value + 1) = 27.
        $out = HighLevelEncoder::encode("caf\xC3\xA9");
        self::assertSame(241, $out[0], 'ECI character');
        self::assertSame(27, $out[1], 'ECI 26 (UTF-8) encoded as value + 1');
    }

    public function testAsciiOnlyPayloadEmitsNoEci(): void
    {
        // Pure ASCII must not carry an ECI prefix.
        $out = HighLevelEncoder::encode('ABC');
        self::assertSame([66, 67, 68], $out);
    }

    public function testUtf8PayloadBase256LengthUsesAbsolutePosition(): void
    {
        // 'cafe a la francaise' (with UTF-8 accents). After the 2-codeword ECI
        // prefix [241, 27], the leading run latches to Base256 (latch at symbol
        // position 3). The 8-byte run's length codeword randomizes at its ABSOLUTE
        // position 4 -> (8 + ((149*4) % 255) + 1) % 256 = (8 + 87) % 256 = 95.
        // A block-relative index would un-randomize to a bogus length and overrun.
        $out = HighLevelEncoder::encode("caf\xC3\xA9 \xC3\xA0 la fran\xC3\xA7aise");
        self::assertSame([241, 27], [$out[0], $out[1]], 'UTF-8 ECI prefix');
        self::assertSame(231, $out[2], 'leading run latches to Base256');
        self::assertSame(95, $out[3], 'length codeword randomized at absolute position 4');
    }
}
