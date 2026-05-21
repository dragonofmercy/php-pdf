<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\Aztec\LowLevelEncoder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LowLevelEncoder::stuffBits().
 *
 * All expected values are derived by simulating the zxing-java algorithm
 * (Encoder.stuffBits in com.google.zxing.aztec.encoder.Encoder, Apache 2.0)
 * in PHP. The algorithm is:
 *
 *   mask = (1 << wordSize) - 2
 *   for i in 0..n step wordSize:
 *       read wordSize bits starting at i; bits past end of input are treated as '1'
 *       word = integer assembled MSB-first from those bits
 *       if (word & mask) == mask:   // top (wordSize-1) bits all 1
 *           emit (word & mask)      // top bits + forced 0 LSB
 *           advance by (wordSize-1) instead of wordSize
 *       elif (word & mask) == 0:   // top (wordSize-1) bits all 0
 *           emit (word | 1)         // top bits + forced 1 LSB
 *           advance by (wordSize-1) instead of wordSize
 *       else:
 *           emit word as-is, advance by wordSize
 *
 * Important: bits PAST the end of the input string are treated as '1' (not '0').
 * This is the zxing convention and is what makes the padding interact with stuffing.
 *
 * Derivation of each test vector is documented inline.
 */
final class LowLevelEncoderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Empty input
    // -----------------------------------------------------------------------

    /**
     * Empty bitstream: the loop never executes, result is an empty array.
     * (zxing: n=0, loop condition i<n is false immediately.)
     */
    public function testEmptyInputProducesNoCodes(): void
    {
        self::assertSame([], LowLevelEncoder::stuffBits('', 6));
        self::assertSame([], LowLevelEncoder::stuffBits('', 8));
        self::assertSame([], LowLevelEncoder::stuffBits('', 12));
    }

    // -----------------------------------------------------------------------
    // wordSize = 6, exact multiples
    // -----------------------------------------------------------------------

    /**
     * 12 bits all-zero at wordSize=6.
     *
     * Derivation:
     *   mask = (1<<6)-2 = 62 = 111110b
     *
     *   i=0: bits[0..5] = 000000, word=0.  (word & mask)=0 => ALL-TOP-0.
     *        emit (word | 1) = 1 = 000001b.  advance by 5 -> i=5.
     *
     *   i=5: bits[5..10] = 000000 (bits 5..10 are all '0' since n=12), word=0.
     *        (word & mask)=0 => ALL-TOP-0.
     *        emit (word | 1) = 1 = 000001b.  advance by 5 -> i=10.
     *
     *   i=10: bits[10..15]: bit10='0', bit11='0', bits 12..15 past end => '1'.
     *         word = 0*32 + 0*16 + 1*8 + 1*4 + 1*2 + 1*1 = 0b001111 = 15.
     *         (word & mask) = 15 & 62 = 14 = 001110b.  Not all-0, not mask(62).
     *         emit 15 = 001111b.  advance by 6 -> i=16 >= 12, stop.
     *
     *   Result: [1, 1, 15]
     */
    public function testAllZero12BitsWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits(str_repeat('0', 12), 6);
        self::assertSame([1, 1, 15], $result);
    }

    /**
     * 12 bits all-one at wordSize=6.
     *
     * Derivation:
     *   mask = 62 = 111110b
     *
     *   i=0: bits[0..5] = 111111, word=63.  (word & mask)=62=mask => ALL-TOP-1.
     *        emit (word & mask) = 62 = 111110b.  advance by 5 -> i=5.
     *
     *   i=5: bits[5..10] = 111111, word=63.  Same => emit 62.  advance 5 -> i=10.
     *
     *   i=10: bits[10..15]: bits 10..11='1', bits 12..15 past end => '1'.
     *          word=63 = 111111b.  (word & mask)=62=mask => ALL-TOP-1.
     *          emit 62.  advance 5 -> i=15 >= 12, stop.
     *
     *   Result: [62, 62, 62]
     */
    public function testAllOne12BitsWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits(str_repeat('1', 12), 6);
        self::assertSame([62, 62, 62], $result);
    }

    /**
     * 12 bits '010101101010' at wordSize=6 - no stuffing needed.
     *
     * Derivation:
     *   i=0: bits[0..5] = '010101', word = 0*32+1*16+0*8+1*4+0*2+1*1 = 21 = 010101b.
     *        (word & mask) = 21 & 62 = 20 = 010100b. Not 0, not 62. emit 21. advance 6.
     *
     *   i=6: bits[6..11] = '101010', word = 1*32+0*16+1*8+0*4+1*2+0*1 = 42 = 101010b.
     *        (word & mask) = 42 & 62 = 42. Not 0, not 62. emit 42. advance 6.
     *
     *   Result: [21, 42]
     */
    public function testNormalBitsNoStuffingNeeded(): void
    {
        $result = LowLevelEncoder::stuffBits('010101101010', 6);
        self::assertSame([21, 42], $result);
    }

    /**
     * 6 bits all-zero at wordSize=6.
     *
     * Derivation:
     *   i=0: bits[0..5] = '000000', word=0. ALL-TOP-0.
     *        emit 1 = 000001b.  advance 5 -> i=5.
     *
     *   i=5: bits[5..10]: bit5='0', bits 6..10 past end => '1'.
     *         word = 0*32 + 1*16 + 1*8 + 1*4 + 1*2 + 1*1 = 31 = 011111b.
     *         (word & mask) = 31 & 62 = 30 = 011110b.  Not 0, not 62.
     *         emit 31 = 011111b.  advance 6 -> i=11 >= 6, stop.
     *
     *   Result: [1, 31]
     */
    public function testExactOneWordAllZeroWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits('000000', 6);
        self::assertSame([1, 31], $result);
    }

    /**
     * 6 bits all-one '111111' at wordSize=6.
     *
     * Derivation:
     *   i=0: bits[0..5] = '111111', word=63. (word & mask)=62=mask => ALL-TOP-1.
     *        emit 62 = 111110b.  advance 5 -> i=5.
     *
     *   i=5: bits[5..10]: bit5='1', bits 6..10 past end => '1'.
     *         word = 63 = 111111b. ALL-TOP-1.
     *         emit 62.  advance 5 -> i=10 >= 6, stop.
     *
     *   Result: [62, 62]
     */
    public function testExactOneWordAllOneWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits('111111', 6);
        self::assertSame([62, 62], $result);
    }

    // -----------------------------------------------------------------------
    // Partial final codeword (input length not a multiple of wordSize)
    // -----------------------------------------------------------------------

    /**
     * Single '0' bit at wordSize=6.
     *
     * Derivation:
     *   i=0: bits[0..5]: bit0='0', bits 1..5 past end => '1'.
     *         word = 0*32 + 1*16 + 1*8 + 1*4 + 1*2 + 1*1 = 31 = 011111b.
     *         (word & mask) = 31 & 62 = 30.  Not 0, not 62.
     *         emit 31.  advance 6 -> i=6 >= 1, stop.
     *
     *   Result: [31]
     */
    public function testSingleZeroBitWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits('0', 6);
        self::assertSame([31], $result);
    }

    /**
     * Single '1' bit at wordSize=6.
     *
     * Derivation:
     *   i=0: bits[0..5]: bit0='1', bits 1..5 past end => '1'.
     *         word = 63 = 111111b. ALL-TOP-1.
     *         emit 62 = 111110b.  advance 5 -> i=5 >= 1, stop.
     *
     *   Result: [62]
     */
    public function testSingleOneBitWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits('1', 6);
        self::assertSame([62], $result);
    }

    /**
     * 5 bits all-zero '00000' at wordSize=6.
     *
     * Derivation:
     *   i=0: bits[0..5]: bits 0..4='0', bit5 past end => '1'.
     *         word = 0*32+0*16+0*8+0*4+0*2+1*1 = 1 = 000001b.
     *         (word & mask) = 1 & 62 = 0 => ALL-TOP-0.
     *         emit (word | 1) = 1 | 1 = 1 = 000001b.  advance 5 -> i=5 >= 5, stop.
     *
     *   Result: [1]
     */
    public function testFiveBitsAllZeroWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits('00000', 6);
        self::assertSame([1], $result);
    }

    /**
     * 5 bits '10101' at wordSize=6 - partial codeword padded with '1'.
     *
     * Derivation:
     *   i=0: bits[0..5]: bits='10101', bit5 past end => '1'.
     *         word = 1*32+0*16+1*8+0*4+1*2+1*1 = 43 = 101011b.
     *         (word & mask) = 43 & 62 = 42 = 101010b.  Not 0, not 62.
     *         emit 43.  advance 6 -> stop.
     *
     *   Result: [43]
     */
    public function testFiveBitsMixedWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits('10101', 6);
        self::assertSame([43], $result);
    }

    // -----------------------------------------------------------------------
    // wordSize = 8
    // -----------------------------------------------------------------------

    /**
     * 16 bits all-zero at wordSize=8.
     *
     * Derivation:
     *   mask = (1<<8)-2 = 254 = 11111110b
     *
     *   i=0: bits[0..7] = 00000000, word=0. (word & mask)=0 => ALL-TOP-0.
     *        emit (word | 1) = 1 = 00000001b.  advance 7 -> i=7.
     *
     *   i=7: bits[7..14]: all '0' (n=16), word=0. ALL-TOP-0.
     *         emit 1.  advance 7 -> i=14.
     *
     *   i=14: bits[14..21]: bits14='0', bit15='0', bits 16..21 past end => '1'.
     *          word = 0*128+0*64+1*32+1*16+1*8+1*4+1*2+1*1 = 63 = 00111111b.
     *          (word & mask) = 63 & 254 = 62 = 00111110b.  Not 0, not 254.
     *          emit 63.  advance 8 -> i=22 >= 16, stop.
     *
     *   Result: [1, 1, 63]
     */
    public function testAllZero16BitsWordSize8(): void
    {
        $result = LowLevelEncoder::stuffBits(str_repeat('0', 16), 8);
        self::assertSame([1, 1, 63], $result);
    }

    /**
     * 8 bits all-one '11111111' at wordSize=8.
     *
     * Derivation:
     *   mask = 254 = 11111110b
     *
     *   i=0: bits[0..7] = 11111111, word=255. (word & mask)=254=mask => ALL-TOP-1.
     *        emit 254 = 11111110b.  advance 7 -> i=7.
     *
     *   i=7: bits[7..14]: bit7='1', bits 8..14 past end => '1'.
     *         word=255. ALL-TOP-1. emit 254.  advance 7 -> i=14 >= 8, stop.
     *
     *   Result: [254, 254]
     */
    public function testAllOne8BitsWordSize8(): void
    {
        $result = LowLevelEncoder::stuffBits('11111111', 8);
        self::assertSame([254, 254], $result);
    }

    // -----------------------------------------------------------------------
    // wordSize = 10
    // -----------------------------------------------------------------------

    /**
     * 10 bits '0101010101' at wordSize=10 - no stuffing needed.
     *
     * Derivation:
     *   mask = (1<<10)-2 = 1022 = 1111111110b
     *
     *   i=0: bits = '0101010101', word = 0b0101010101 = 341.
     *        (word & mask) = 341 & 1022 = 340. Not 0, not 1022.
     *        emit 341.  advance 10.
     *
     *   Result: [341]
     */
    public function testNormalBitsWordSize10(): void
    {
        $result = LowLevelEncoder::stuffBits('0101010101', 10);
        self::assertSame([341], $result);
    }

    /**
     * 10 bits all-zero at wordSize=10.
     *
     * Derivation:
     *   mask = 1022
     *
     *   i=0: bits[0..9] = 0000000000, word=0. ALL-TOP-0.
     *        emit 1.  advance 9 -> i=9.
     *
     *   i=9: bits[9..18]: bit9='0', bits 10..18 past end => '1'.
     *         word = 0*512 + 1*256+1*128+1*64+1*32+1*16+1*8+1*4+1*2+1*1 = 511 = 0111111111b.
     *         (word & mask) = 511 & 1022 = 510 = 0111111110b. Not 0, not 1022.
     *         emit 511.  advance 10 -> stop.
     *
     *   Result: [1, 511]
     */
    public function testAllZero10BitsWordSize10(): void
    {
        $result = LowLevelEncoder::stuffBits(str_repeat('0', 10), 10);
        self::assertSame([1, 511], $result);
    }

    // -----------------------------------------------------------------------
    // wordSize = 12
    // -----------------------------------------------------------------------

    /**
     * 12 bits all-zero at wordSize=12.
     *
     * Derivation:
     *   mask = (1<<12)-2 = 4094 = 111111111110b
     *
     *   i=0: bits[0..11] = 000000000000, word=0. (word & mask)=0 => ALL-TOP-0.
     *        emit (word | 1) = 1 = 000000000001b.  advance 11 -> i=11.
     *
     *   i=11: bits[11..22]: bit11='0', bits 12..22 past end => '1'.
     *          word = 0*2048 + 1*1024+1*512+1*256+1*128+1*64+1*32+1*16+1*8+1*4+1*2+1*1
     *               = 2047 = 011111111111b.
     *          (word & mask) = 2047 & 4094 = 2046 = 011111111110b. Not 0, not 4094.
     *          emit 2047.  advance 12 -> stop.
     *
     *   Result: [1, 2047]
     */
    public function testAllZero12BitsWordSize12(): void
    {
        $result = LowLevelEncoder::stuffBits(str_repeat('0', 12), 12);
        self::assertSame([1, 2047], $result);
    }

    /**
     * Stuff bit triggered mid-stream: '00000101' at wordSize=6 (8 bits, 2 codewords with stuff).
     *
     * Derivation:
     *   mask = 62 = 111110b
     *   input = '00000101' (n=8)
     *
     *   i=0: bits[0..5] = '000001', word = 1 = 000001b.
     *        (word & mask) = 1 & 62 = 0 => ALL-TOP-0.
     *        emit (word | 1) = 1 = 000001b.  advance 5 -> i=5.
     *
     *   i=5: bits[5..10]: bit5='1', bit6='0', bit7='1', bits 8..10 past end => '1'.
     *         word = 1*32+0*16+1*8+1*4+1*2+1*1 = 47 = 101111b.
     *         (word & mask) = 47 & 62 = 46 = 101110b. Not 0, not 62.
     *         emit 47.  advance 6 -> stop.
     *
     *   (Note: input[5]='1', input[6]='0', input[7]='1'; past-end bits at [8],[9],[10] are '1'.)
     *   (word bits: 1,0,1,1,1,1 -> MSB-first -> 101111 = 47.)
     *
     *   Result: [1, 47]
     */
    public function testStuffBitMidStream(): void
    {
        $result = LowLevelEncoder::stuffBits('00000101', 6);
        self::assertSame([1, 47], $result);
    }

    /**
     * All-ones input longer than one word: '11111111111111' (14 bits) at wordSize=6.
     *
     * Derivation:
     *   i=0: word=63 (000000+padding all 1). ALL-TOP-1. emit 62. advance 5 -> i=5.
     *   i=5: bits[5..10]='111111', word=63. ALL-TOP-1. emit 62. advance 5 -> i=10.
     *   i=10: bits[10..15]='1111'+past end('11'). word=63. ALL-TOP-1. emit 62. advance 5 -> i=15 >= 14, stop.
     *
     *   Result: [62, 62, 62]
     */
    public function testAllOne14BitsWordSize6(): void
    {
        $result = LowLevelEncoder::stuffBits('11111111111111', 6);
        self::assertSame([62, 62, 62], $result);
    }
}
