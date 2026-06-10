<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Filter\FlateDecoder;
use DragonOfMercy\PhpPdf\Reader\Filter\PredictorDecoder;
use PHPUnit\Framework\TestCase;

final class FlateDecoderTest extends TestCase
{
    public function testDecodesZlibStream(): void
    {
        $compressed = gzcompress('hello stream', 9);
        self::assertIsString($compressed);
        self::assertSame('hello stream', FlateDecoder::decode($compressed));
    }

    public function testDecodesRawDeflateWithoutZlibHeader(): void
    {
        $raw = gzdeflate('payload', 9);
        self::assertIsString($raw);
        self::assertSame('payload', FlateDecoder::decode($raw));
    }

    public function testThrowsOnGarbage(): void
    {
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('FlateDecode');
        FlateDecoder::decode('definitely not deflate');
    }

    public function testAppliesPngUpPredictor(): void
    {
        // 2 rows of 4 columns, 1 byte per pixel; PNG predictor "Up" (filter byte 2)
        $row1 = "\x02" . "\x01\x02\x03\x04";  // Up with no prior row = literal
        $row2 = "\x02" . "\x01\x01\x01\x01";  // adds to previous row
        $compressed = gzcompress($row1 . $row2, 9);
        self::assertIsString($compressed);
        $decoded = FlateDecoder::decode($compressed, predictor: 12, colors: 1, bitsPerComponent: 8, columns: 4);
        self::assertSame("\x01\x02\x03\x04\x02\x03\x04\x05", $decoded);
    }

    public function testPngSubPredictor(): void
    {
        $row = "\x01" . "\x05\x01\x01";  // Sub: each byte adds the byte one pixel left
        self::assertSame("\x05\x06\x07", PredictorDecoder::apply($row, 11, 1, 8, 3));
    }

    public function testPngAveragePredictor(): void
    {
        $row1 = "\x00" . "\x02\x04";              // None: literal 02 04
        $row2 = "\x03" . "\x02\x02";              // Average: byte + floor((left+up)/2)
        // row2: first byte: 2 + floor((0+2)/2) = 3 ; second: 2 + floor((3+4)/2) = 5
        self::assertSame("\x02\x04\x03\x05", PredictorDecoder::apply($row1 . $row2, 13, 1, 8, 2));
    }

    public function testPngPaethPredictor(): void
    {
        $row1 = "\x00" . "\x0A\x14";              // literal 10 20
        $row2 = "\x04" . "\x01\x01";              // Paeth
        // row2 b1: paeth(left=0, up=10, upLeft=0) = 10 -> 1+10 = 11
        //      b2: paeth(left=11, up=20, upLeft=10) -> p=21, pa=|21-11|=10, pb=|21-20|=1, pc=|21-10|=11 -> up=20 -> 1+20=21
        self::assertSame("\x0A\x14\x0B\x15", PredictorDecoder::apply($row1 . $row2, 14, 1, 8, 2));
    }

    public function testTiffPredictor2(): void
    {
        // 1 row, 4 columns, 1 color, 8 bpc: cumulative sum
        self::assertSame("\x01\x03\x06\x0A", PredictorDecoder::apply("\x01\x02\x03\x04", 2, 1, 8, 4));
    }

    public function testMultiBytePixelsUseBytesPerPixelStride(): void
    {
        // colors=3 (RGB), Sub predictor: left reference is 3 bytes back
        $row = "\x01" . "\x01\x02\x03\x01\x01\x01";
        self::assertSame("\x01\x02\x03\x02\x03\x04", PredictorDecoder::apply($row, 11, 3, 8, 2));
    }

    public function testTruncatedPredictorRowThrows(): void
    {
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('predictor');
        PredictorDecoder::apply("\x02\x01", 12, 1, 8, 4);
    }

    public function testTiffPredictorRequires8Bpc(): void
    {
        $this->expectException(PdfParseException::class);
        PredictorDecoder::apply("\x01", 2, 1, 4, 2);
    }
}
