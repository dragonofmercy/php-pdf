<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\PngFilters;
use PHPUnit\Framework\TestCase;

final class PngFiltersTest extends TestCase
{
    public function testNoneFilterPassesThrough(): void
    {
        // 2x2 grayscale (bpp=1): each row is filter(0) + 2 bytes.
        $filtered = "\x00\x10\x20" . "\x00\x30\x40";
        $raw = PngFilters::unfilter($filtered, width: 2, height: 2, bpp: 1);
        self::assertSame("\x10\x20\x30\x40", $raw);
    }

    public function testSubFilter(): void
    {
        // bpp=1: row [0x10, 0x20], filtered with Sub becomes [0x10, 0x10] (each byte minus left).
        $filtered = "\x01\x10\x10";
        $raw = PngFilters::unfilter($filtered, width: 2, height: 1, bpp: 1);
        self::assertSame("\x10\x20", $raw);
    }

    public function testUpFilter(): void
    {
        // Two rows, bpp=1. Row 0 is None [0x10, 0x20]; row 1 is Up [0x05, 0x05] meaning
        // raw row1 = filtered + above = [0x15, 0x25].
        $filtered = "\x00\x10\x20" . "\x02\x05\x05";
        $raw = PngFilters::unfilter($filtered, width: 2, height: 2, bpp: 1);
        self::assertSame("\x10\x20\x15\x25", $raw);
    }

    public function testAverageFilter(): void
    {
        // bpp=1: row 0 None [0x10, 0x20]; row 1 Average — recon(x) = filt + floor((a + b) / 2).
        // For col 0 of row 1: a = 0 (leftmost), b = 0x10. filt = 0x05 -> recon = 0x05 + 8 = 0x0D.
        // For col 1 of row 1: a = 0x0D, b = 0x20. floor((0x0D + 0x20) / 2) = floor(0x2D / 2) = 0x16. filt = 0x03 -> recon = 0x19.
        $filtered = "\x00\x10\x20" . "\x03\x05\x03";
        $raw = PngFilters::unfilter($filtered, width: 2, height: 2, bpp: 1);
        self::assertSame("\x10\x20\x0D\x19", $raw);
    }

    public function testPaethFilter(): void
    {
        // bpp=1, 2x2.
        // Row 0 None: [0x80, 0x40].
        // Row 1 Paeth col 0: a=0, b=0x80, c=0. PaethPredictor -> 0x80. filt 0x10 -> recon 0x90.
        // Row 1 Paeth col 1: a=0x90, b=0x40, c=0x80. p = 0x90 + 0x40 - 0x80 = 0x50.
        //   pa=|0x50-0x90|=0x40. pb=|0x50-0x40|=0x10. pc=|0x50-0x80|=0x30.
        //   pb is smallest -> predictor = b = 0x40. filt 0x05 -> recon 0x45.
        $filtered = "\x00\x80\x40" . "\x04\x10\x05";
        $raw = PngFilters::unfilter($filtered, width: 2, height: 2, bpp: 1);
        self::assertSame("\x80\x40\x90\x45", $raw);
    }

    public function testWorksWithBpp3(): void
    {
        // 1 pixel x 2 rows, RGB (bpp=3). Row 0 None RGB=(0x10, 0x20, 0x30).
        // Row 1 Sub: each color minus left. Leftmost subgroup uses 0 for "a", so within the same pixel
        // bytes 0..2: a is 0, 0x05, 0x10 (first byte: a=0; second: a=raw byte 0; third: a=raw byte 1).
        // Actually for bpp=3 row 1 of 1 pixel only, "a" for byte 0 is 0 (no pixel to the left),
        // "a" for byte 1 is 0 (since "a" is bpp=3 to the left), "a" for byte 2 is 0.
        // So Sub raw = filt + 0 -> raw equals filt.
        $filtered = "\x00\x10\x20\x30" . "\x01\x05\x10\x15";
        $raw = PngFilters::unfilter($filtered, width: 1, height: 2, bpp: 3);
        self::assertSame("\x10\x20\x30\x05\x10\x15", $raw);
    }

    public function testRejectsUnknownFilterByte(): void
    {
        $filtered = "\x09\x10\x20";   // filter type 9 doesn't exist (valid: 0..4)
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unknown PNG filter type: 9');
        PngFilters::unfilter($filtered, width: 2, height: 1, bpp: 1);
    }

    public function testRejectsTruncatedInput(): void
    {
        // Promised 2 rows of 2 bytes each (4 bytes data + 2 filter bytes) but provide too little.
        $this->expectException(PdfException::class);
        PngFilters::unfilter("\x00\x10", width: 2, height: 2, bpp: 1);
    }
}
