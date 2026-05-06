<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\PngColorType;
use DragonOfMercy\PhpPdf\Image\PngMetadata;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class PngMetadataTest extends TestCase
{
    public function testParsesIhdrRgb(): void
    {
        $meta = PngMetadata::parse(TestImageFactory::pngRgb(width: 8, height: 4));
        self::assertSame(8, $meta->width);
        self::assertSame(4, $meta->height);
        self::assertSame(8, $meta->bitDepth);
        self::assertSame(PngColorType::RGB, $meta->colorType);
        self::assertNull($meta->palette);
        self::assertNull($meta->alphaBytes);
        self::assertNull($meta->colorBytes);
        self::assertNotSame('', $meta->idatBytes);
    }

    public function testParsesGrayscale(): void
    {
        $meta = PngMetadata::parse(TestImageFactory::pngGray(width: 4, height: 2));
        self::assertSame(PngColorType::GRAY, $meta->colorType);
        self::assertNull($meta->palette);
        self::assertNull($meta->alphaBytes);
    }

    public function testParsesPaletteWithoutTrns(): void
    {
        $meta = PngMetadata::parse(TestImageFactory::pngPalette(width: 4, height: 4));
        self::assertSame(PngColorType::PALETTE, $meta->colorType);
        self::assertNotNull($meta->palette);
        self::assertSame(6, strlen($meta->palette));   // 2 colors x 3 bytes
        self::assertNull($meta->alphaBytes);
    }

    public function testConcatenatesMultiIdat(): void
    {
        $singleBytes = TestImageFactory::pngRgb(width: 4, height: 4);
        $multiBytes = TestImageFactory::pngRgbMultiIdat(width: 4, height: 4, pieces: 3);
        $singleMeta = PngMetadata::parse($singleBytes);
        $multiMeta = PngMetadata::parse($multiBytes);
        // Concatenated IDAT must equal the single IDAT stream byte-for-byte.
        self::assertSame($singleMeta->idatBytes, $multiMeta->idatBytes);
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PNG signature');
        PngMetadata::parse('not-a-png-file-at-all-just-text-data');
    }

    public function testRejects16BitDepth(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('16-bit');
        PngMetadata::parse(TestImageFactory::pngRgb16Bit());
    }

    public function testRejectsAdam7Interlacing(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Adam7');
        PngMetadata::parse(TestImageFactory::pngRgbAdam7());
    }

    public function testRejectsMissingIhdr(): void
    {
        $bytes = TestImageFactory::PNG_SIGNATURE
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PNG missing IHDR');
        PngMetadata::parse($bytes);
    }

    public function testRejectsPaletteWithoutPlte(): void
    {
        // IHDR colorType=3 but no PLTE chunk.
        $ihdr = pack('NN', 2, 2) . chr(8) . chr(3) . "\x00\x00\x00";
        $idatRaw = gzcompress("\x00\x00\x00\x00\x00\x00", 6);
        self::assertIsString($idatRaw, 'gzcompress failed');
        $idat = $idatRaw;
        $bytes = TestImageFactory::PNG_SIGNATURE
            . pack('N', strlen($ihdr)) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', strlen($idat)) . 'IDAT' . $idat . pack('N', crc32('IDAT' . $idat))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PLTE');
        PngMetadata::parse($bytes);
    }

    public function testParsesGrayAlpha(): void
    {
        $meta = PngMetadata::parse(TestImageFactory::pngGrayAlpha(width: 2, height: 2));
        self::assertSame(PngColorType::GRAY_ALPHA, $meta->colorType);
        self::assertNotNull($meta->colorBytes);
        self::assertNotNull($meta->alphaBytes);
        // After zlib-decompress + unfilter, color stream should be 2x2 = 4 bytes and alpha 4 bytes.
        $color = gzuncompress($meta->colorBytes);
        $alpha = gzuncompress($meta->alphaBytes);
        self::assertNotFalse($color);
        self::assertNotFalse($alpha);
        // Each scanline = 1 filter byte + 2 data bytes => 6 bytes total per stream.
        self::assertSame(6, strlen($color));
        self::assertSame(6, strlen($alpha));
        // Color bytes (skip filter byte at start of each row).
        self::assertSame("\x80\x80", substr($color, 1, 2));
        self::assertSame("\x80\x80", substr($alpha, 1, 2));
    }

    public function testParsesRgbAlpha(): void
    {
        $meta = PngMetadata::parse(TestImageFactory::pngRgbAlpha(width: 2, height: 2));
        self::assertSame(PngColorType::RGB_ALPHA, $meta->colorType);
        self::assertNotNull($meta->colorBytes);
        self::assertNotNull($meta->alphaBytes);
        $color = gzuncompress($meta->colorBytes);
        $alpha = gzuncompress($meta->alphaBytes);
        self::assertNotFalse($color);
        self::assertNotFalse($alpha);
        // 2 rows; each row: 1 filter byte + 2 pixels of 3 RGB bytes = 7 color bytes.
        self::assertSame(2 * (1 + 6), strlen($color));
        // Alpha: 1 filter byte + 2 alpha bytes per row = 6 bytes.
        self::assertSame(2 * (1 + 2), strlen($alpha));
        // First color row data (skip filter byte): R=0xFF G=0x00 B=0x00 R=0xFF G=0x00 B=0x00.
        self::assertSame("\xFF\x00\x00\xFF\x00\x00", substr($color, 1, 6));
        self::assertSame("\xFF\xFF", substr($alpha, 1, 2));
    }

    public function testParsesPaletteWithTrnsGeneratesAlpha(): void
    {
        $meta = PngMetadata::parse(TestImageFactory::pngPaletteWithTrns(width: 2, height: 2));
        self::assertSame(PngColorType::PALETTE, $meta->colorType);
        self::assertNotNull($meta->palette);
        self::assertNotNull($meta->alphaBytes);
        // Color (idatBytes) is preserved verbatim.
        self::assertNotSame('', $meta->idatBytes);
        // Alpha stream: 1 filter byte + 2 alpha bytes per row, both index 0 -> tRNS[0] = 0x00.
        $alpha = gzuncompress($meta->alphaBytes);
        self::assertNotFalse($alpha);
        self::assertSame(2 * (1 + 2), strlen($alpha));
        self::assertSame("\x00\x00", substr($alpha, 1, 2));
    }

    public function testPaletteWithoutTrnsHasNoAlpha(): void
    {
        $meta = PngMetadata::parse(TestImageFactory::pngPalette());
        self::assertNull($meta->alphaBytes);
        self::assertNull($meta->colorBytes);
    }
}
