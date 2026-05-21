<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\Aztec\Encoder;
use DragonOfMercy\PhpPdf\Barcode\AztecEc;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Encoder::encode().
 *
 * The orchestrator iterates Compact 1..4 then Full 4..32 (Full 1..3 are never
 * selected: Compact 2..4 cover the same module size with strictly more data),
 * computes the symbol capacity from zxing-java's totalBitsInLayer formula,
 * applies LowLevelEncoder bit-stuffing, then checks that the data plus the
 * required EC bits fit inside the layer's usable bit budget. The first symbol
 * that fits is selected.
 *
 * EC bit budget formula: eccBits = bitSize * percent / 100 + 11 (integer division).
 * Capacity table verified against zxing-java Encoder.java (Apache 2.0).
 */
final class EncoderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Smallest symbol selection
    // -----------------------------------------------------------------------

    /**
     * A single 'A' encodes in 5 bits (UPPER mode 'A' = 00010). With MEDIUM EC
     * the budget is tiny: bitSize=5, eccBits = 5*23/100 + 11 = 12, totalSizeBits = 17.
     * Compact 1 has 102 usable bits at wordSize=6, more than enough. Result must be
     * Compact 1 layer with 6-bit codewords.
     */
    public function testSingleCharFitsCompactLayer1(): void
    {
        $result = Encoder::encode('A', AztecEc::MEDIUM);

        self::assertTrue($result->compact);
        self::assertSame(1, $result->layers);
        self::assertSame(6, $result->codewordBits);
        // Total budget for Compact 1 = 17 codewords (102 / 6).
        self::assertSame(17, $result->totalCodewords());
        // At least one data codeword must be present.
        self::assertGreaterThan(0, count($result->dataCodewords));
        // EC takes the remaining budget.
        self::assertSame(17, count($result->dataCodewords) + count($result->ecCodewords));
    }

    /**
     * 60 ASCII characters (12 x "HELLO") should fit a Compact 3 or Compact 4 layer
     * symbol with MEDIUM EC. The exact layer depends on the high-level encoding,
     * but it must be Compact (1-4 layers) with codewordBits in {6, 8}.
     */
    public function testMediumPayloadFitsCompact(): void
    {
        $result = Encoder::encode(str_repeat('HELLO', 12), AztecEc::MEDIUM);

        self::assertTrue($result->compact);
        self::assertGreaterThanOrEqual(3, $result->layers);
        self::assertLessThanOrEqual(4, $result->layers);
        self::assertContains($result->codewordBits, [6, 8]);
    }

    /**
     * 200 'A' chars (~1000 bits at the HighLevelEncoder rate) will not fit any
     * Compact symbol (max C4 = 608 bits) and so must fall into Full Range.
     * The selected variant must have compact=false.
     */
    public function testLongPayloadFallsIntoFullRange(): void
    {
        $result = Encoder::encode(str_repeat('A', 200), AztecEc::MEDIUM);

        self::assertFalse($result->compact);
        self::assertGreaterThanOrEqual(4, $result->layers);
        self::assertLessThanOrEqual(32, $result->layers);
        self::assertContains($result->codewordBits, [8, 10, 12]);
    }

    // -----------------------------------------------------------------------
    // Capacity limit
    // -----------------------------------------------------------------------

    /**
     * A 10 000-character payload exceeds the largest symbol (Full Range layer 32,
     * 19968 total bits / 1664 codewords at 12 bits each). The encoder must throw
     * PdfException with a message containing "Aztec data too large".
     */
    public function testOversizedPayloadThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/Aztec data too large/');

        Encoder::encode(str_repeat('A', 10000), AztecEc::MEDIUM);
    }

    // -----------------------------------------------------------------------
    // EC level differences
    // -----------------------------------------------------------------------

    /**
     * Same payload at LOW vs HIGH EC: HIGH must allocate more EC codewords.
     * This may also bump up the layer count if the payload was near a boundary,
     * but the EC codeword count itself must be strictly higher for HIGH.
     */
    public function testHigherEcLevelAllocatesMoreEcCodewords(): void
    {
        $payload = str_repeat('HELLO WORLD ', 8);

        $low = Encoder::encode($payload, AztecEc::LOW);
        $high = Encoder::encode($payload, AztecEc::HIGH);

        self::assertGreaterThan(count($low->ecCodewords), count($high->ecCodewords));
    }

    /**
     * LOW vs MAX on a payload near the Compact 1 boundary: LOW fits C1 with
     * a small EC tail, MAX overflows C1 and is forced to C2, which dedicates
     * far more of its 40-codeword budget to EC. The total EC count must
     * therefore be strictly higher at MAX.
     */
    public function testMaxEcAllocatesMoreThanLow(): void
    {
        $payload = 'HI THERE FRIEND';

        $low = Encoder::encode($payload, AztecEc::LOW);
        $max = Encoder::encode($payload, AztecEc::MAX);

        self::assertGreaterThan(count($low->ecCodewords), count($max->ecCodewords));
    }

    // -----------------------------------------------------------------------
    // Internal consistency
    // -----------------------------------------------------------------------

    /**
     * Data + EC codewords always exactly fill the symbol's usable codeword budget
     * (zxing uses the entire remaining capacity for EC, not just the minimum).
     */
    public function testTotalCodewordsEqualsCapacity(): void
    {
        // Compact 1: 17 codewords total.
        $result = Encoder::encode('HI', AztecEc::MEDIUM);
        self::assertSame(17, $result->totalCodewords());
        self::assertSame(6, $result->codewordBits);
    }

    /**
     * Codewords are always within their bit range [0, 2^bits - 1].
     */
    public function testCodewordsAreInRange(): void
    {
        $result = Encoder::encode(str_repeat('ABC', 30), AztecEc::MEDIUM);
        $max = (1 << $result->codewordBits) - 1;
        foreach ($result->dataCodewords as $cw) {
            self::assertGreaterThanOrEqual(0, $cw);
            self::assertLessThanOrEqual($max, $cw);
        }
        foreach ($result->ecCodewords as $cw) {
            self::assertGreaterThanOrEqual(0, $cw);
            self::assertLessThanOrEqual($max, $cw);
        }
    }

    /**
     * Compact 4 has the 64-data-words ceiling per zxing (mode message field is
     * only 6 bits wide, so messageSize - 1 must fit in 6 bits). If a payload
     * stuffs to between 65 and 76 codewords at wordSize 8, the encoder must
     * skip C4 and fall into Full 4+ instead.
     *
     * Empirically a 80-character ASCII payload exceeds 64 data words at C4
     * (wordSize=8) so the encoder falls into Full Range. We assert at minimum
     * that compact=false OR the data codeword count is <= 64.
     */
    public function testCompactFourMaxDataWordsConstraint(): void
    {
        // Use a payload that stresses Compact 4: ~70 mixed-mode chars.
        // The exact bit count varies by content; we only assert the invariant.
        $result = Encoder::encode(str_repeat('A1', 35), AztecEc::LOW);

        if ($result->compact) {
            self::assertLessThanOrEqual(64, count($result->dataCodewords));
        }
    }
}
