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
}
