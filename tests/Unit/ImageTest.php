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

    public function testBytesAreStoredVerbatim(): void
    {
        $original = TestImageFactory::pngRgb(width: 4, height: 4);
        $img = Image::fromBytes($original);
        self::assertSame($original, $img->bytes);
    }
}
