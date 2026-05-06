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
}
