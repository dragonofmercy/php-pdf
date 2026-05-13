<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\JpegMetadata;
use DragonOfMercy\PhpPdf\Image\PngMetadata;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    public function testFromBytesAutoDetectsJpeg(): void
    {
        $img = Image::fromBytes(TestImageFactory::stubJpegRgb(width: 4, height: 2));
        self::assertSame(ImageFormat::JPEG, $img->format);
        self::assertSame(4, $img->width);
        self::assertSame(2, $img->height);
        self::assertInstanceOf(JpegMetadata::class, $img->metadata);
    }

    public function testFromBytesAutoDetectsPng(): void
    {
        $img = Image::fromBytes(TestImageFactory::pngRgb(width: 8, height: 4));
        self::assertSame(ImageFormat::PNG, $img->format);
        self::assertSame(8, $img->width);
        self::assertSame(4, $img->height);
        self::assertInstanceOf(PngMetadata::class, $img->metadata);
    }

    public function testFromFileReadsAndParses(): void
    {
        $path = sys_get_temp_dir() . '/phppdf-test-' . uniqid() . '.png';
        file_put_contents($path, TestImageFactory::pngRgb(width: 6, height: 6));
        try {
            $img = Image::fromFile($path);
            self::assertSame(ImageFormat::PNG, $img->format);
            self::assertSame(6, $img->width);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileThrowsWhenMissing(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Cannot read image file');
        Image::fromFile('/this/path/does/not/exist.png');
    }

    public function testFromBytesRejectsUnknownMagic(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unsupported image format');
        Image::fromBytes('GIF89a' . str_repeat("\x00", 32));
    }

    public function testFromBytesRejectsTooSmallInput(): void
    {
        $this->expectException(PdfException::class);
        Image::fromBytes("\xFF");
    }

    public function testFromBase64DecodesRawString(): void
    {
        $original = TestImageFactory::pngRgb(width: 4, height: 4);
        $img = Image::fromBase64(base64_encode($original));
        self::assertSame(ImageFormat::PNG, $img->format);
        self::assertSame(4, $img->width);
        self::assertSame($original, $img->bytes);
    }

    public function testFromBase64StripsDataUriPrefix(): void
    {
        $original = TestImageFactory::stubJpegRgb(width: 3, height: 2);
        $img = Image::fromBase64('data:image/jpeg;base64,' . base64_encode($original));
        self::assertSame(ImageFormat::JPEG, $img->format);
        self::assertSame(3, $img->width);
        self::assertSame(2, $img->height);
    }

    public function testFromBase64RejectsInvalidBase64(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid base64');
        Image::fromBase64('!!!not-valid-base64!!!');
    }

    public function testFromBase64RejectsMalformedDataUri(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid data URI');
        Image::fromBase64('data:image/png;base64');
    }

    public function testBytesAreStoredVerbatim(): void
    {
        $original = TestImageFactory::pngRgb(width: 4, height: 4);
        $img = Image::fromBytes($original);
        self::assertSame($original, $img->bytes);
    }

    public function testFromBytesDetectsSvgAndRoutesToParser(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><rect x="0" y="0" width="24" height="24" fill="red"/></svg>';
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('SVG parsing not implemented yet');
        Image::fromBytes($svg);
    }

    public function testFromBytesDetectsSvgWithXmlDeclaration(): void
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('SVG parsing not implemented yet');
        Image::fromBytes($svg);
    }

    public function testFromBytesDetectsSvgWithBomAndLeadingWhitespace(): void
    {
        $svg = "\xEF\xBB\xBF  \n<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1 1\"><rect width=\"1\" height=\"1\"/></svg>";
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('SVG parsing not implemented yet');
        Image::fromBytes($svg);
    }
}
