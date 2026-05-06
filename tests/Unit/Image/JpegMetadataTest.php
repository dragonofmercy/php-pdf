<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\JpegMetadata;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class JpegMetadataTest extends TestCase
{
    public function testParsesBaselineRgb(): void
    {
        $bytes = TestImageFactory::stubJpegRgb(width: 64, height: 32);
        $meta = JpegMetadata::parse($bytes);
        self::assertSame(64, $meta->width);
        self::assertSame(32, $meta->height);
        self::assertSame(3, $meta->components);
        self::assertSame(8, $meta->bitsPerComponent);
    }

    public function testParsesGrayscale(): void
    {
        $meta = JpegMetadata::parse(TestImageFactory::stubJpegGray(width: 10, height: 20));
        self::assertSame(1, $meta->components);
        self::assertSame(10, $meta->width);
        self::assertSame(20, $meta->height);
    }

    public function testParsesCmyk(): void
    {
        $meta = JpegMetadata::parse(TestImageFactory::stubJpegCmyk());
        self::assertSame(4, $meta->components);
    }

    public function testParsesProgressiveSof2(): void
    {
        $meta = JpegMetadata::parse(TestImageFactory::stubJpegProgressive(width: 100, height: 50));
        self::assertSame(100, $meta->width);
        self::assertSame(50, $meta->height);
        self::assertSame(3, $meta->components);
    }

    public function testSkipsApp0AndComBeforeSof(): void
    {
        $bytes = TestImageFactory::stubJpegRgbWithApp(width: 8, height: 4);
        $meta = JpegMetadata::parse($bytes);
        self::assertSame(8, $meta->width);
        self::assertSame(4, $meta->height);
    }

    public function testThrowsWhenSofMissing(): void
    {
        $bytes = "\xFF\xD8\xFF\xD9";   // SOI then EOI, no SOF
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('JPEG missing SOF marker');
        JpegMetadata::parse($bytes);
    }

    public function testThrowsOnUnsupportedComponentCount(): void
    {
        // Hand-build a SOF0 with components=2 (not 1, 3, 4).
        $sofPayload = pack('n', 11) . chr(8) . pack('n', 4) . pack('n', 4) . chr(2)
            . chr(1) . chr(0x11) . chr(0)
            . chr(2) . chr(0x11) . chr(0);
        $bytes = "\xFF\xD8" . "\xFF\xC0" . $sofPayload . "\xFF\xD9";
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('JPEG has unsupported component count: 2');
        JpegMetadata::parse($bytes);
    }
}
