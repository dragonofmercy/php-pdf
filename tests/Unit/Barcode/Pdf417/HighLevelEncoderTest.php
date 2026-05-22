<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Barcode\Pdf417\HighLevelEncoder;
use PHPUnit\Framework\TestCase;

final class HighLevelEncoderTest extends TestCase
{
    public function testLongNumericRunLatchesToNumeric(): void
    {
        // A long digit run uses Numeric compaction (latch 902).
        $cw = HighLevelEncoder::encode('12345678901234567890');
        self::assertContains(902, $cw, 'should latch to Numeric mode');
    }

    public function testShortTextStartsInTextMode(): void
    {
        // PDF417 starts in Text/Alpha mode: 'PDF' needs no Numeric/Byte latch at head.
        $cw = HighLevelEncoder::encode('PDF');
        self::assertNotEmpty($cw);
        self::assertNotSame(902, $cw[0]);
        self::assertNotSame(901, $cw[0]);
        self::assertNotSame(924, $cw[0]);
        self::assertNotSame(927, $cw[0]); // no ECI for pure ASCII
    }

    public function testValidUtf8NonAsciiEmitsEci(): void
    {
        $cw = HighLevelEncoder::encode("caf\xC3\xA9");
        self::assertSame(927, $cw[0], 'ECI designator');
        self::assertSame(26, $cw[1], 'ECI 26 = UTF-8');
    }

    public function testRawBinaryNoEci(): void
    {
        // Invalid UTF-8 -> no ECI; encoded via Byte compaction.
        $cw = HighLevelEncoder::encode("\xFF\xFE\xFD\xFC\xFB\xFA");
        self::assertNotSame(927, $cw[0], 'no ECI for raw binary');
        self::assertNotEmpty($cw);
    }

    public function testTextRoundTripIsDecodableLater(): void
    {
        // Structural: a mixed alphanumeric payload produces a non-empty codeword
        // stream within the valid 0-928 range.
        $cw = HighLevelEncoder::encode('PDF417 Sample 2026');
        self::assertNotEmpty($cw);
        foreach ($cw as $c) {
            self::assertGreaterThanOrEqual(0, $c);
            self::assertLessThan(929, $c);
        }
    }

    public function testMixedPunctuationLatchIndependentOfPosition(): void
    {
        // "&;;;;": '&' enters the Mixed submode, then four ';' should latch to
        // Punctuation (pl, 25). The latch lookahead must use the run-relative
        // index, not the absolute start, so the same run encoded after a
        // numeric segment must yield identical text codewords.
        $atZero = HighLevelEncoder::encode('&;;;;');
        $afterNumeric = HighLevelEncoder::encode('1234567890123&;;;;');

        // Strip the leading numeric segment up to and including LATCH_TO_TEXT (900).
        $latch = array_search(900, $afterNumeric, true);
        self::assertNotFalse($latch, 'expected a LATCH_TO_TEXT after the numeric run');
        $textTail = array_slice($afterNumeric, $latch + 1);

        self::assertSame($atZero, $textTail);
    }

    public function testLoneByteAmidTextUsesShiftNotLatch(): void
    {
        // A single non-text byte between two 5-char text runs must be a Byte
        // SHIFT (913), not a Byte LATCH (924) that swallows the trailing text.
        // 0xFF makes the payload invalid UTF-8, so no ECI is emitted.
        $cw = HighLevelEncoder::encode("abcde\xFFabcde");
        self::assertContains(913, $cw, 'expected SHIFT_TO_BYTE for the lone 0xFF');
        self::assertNotContains(924, $cw, 'binary run must not swallow the trailing text');
    }

    public function testByteSixpackMatchesBase900Expansion(): void
    {
        // Six contiguous bytes pack into five base-900 codewords. The packing
        // must equal the canonical 48-bit base-900 expansion (the production
        // code avoids a 48-bit accumulator so it stays correct on 32-bit PHP).
        $bytes = "\x10\x20\x30\x40\x50\x60";
        $method = new \ReflectionMethod(HighLevelEncoder::class, 'encodeBinary');
        /** @var list<int> $out */
        $out = $method->invoke(null, $bytes, 0, 6, 1); // startmode = BYTE_COMPACTION
        $packed = array_slice($out, 1); // drop the latch codeword

        $value = 0;
        foreach (str_split($bytes) as $b) {
            $value = $value * 256 + ord($b);
        }
        $expected = [];
        for ($i = 0; $i < 5; $i++) {
            $expected[] = $value % 900;
            $value = intdiv($value, 900);
        }
        $expected = array_reverse($expected);

        self::assertSame($expected, $packed);
    }
}
