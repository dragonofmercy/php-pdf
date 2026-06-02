<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\RasterBuffer;
use PHPUnit\Framework\TestCase;

final class RasterBufferTest extends TestCase
{
    public function testTransparentByDefault(): void
    {
        $buf = new RasterBuffer(2, 2);
        self::assertSame(2, $buf->width);
        self::assertSame(2, $buf->height);
        self::assertSame([0.0, 0.0, 0.0, 0.0], $buf->pixel(0, 0));
    }

    public function testSetAndGetPixel(): void
    {
        $buf = new RasterBuffer(1, 1);
        $buf->setPixel(0, 0, 0.25, 0.5, 0.75, 1.0);
        self::assertSame([0.25, 0.5, 0.75, 1.0], $buf->pixel(0, 0));
    }

    public function testColorStreamIsStraightRgbBytes(): void
    {
        $buf = new RasterBuffer(1, 1);
        $buf->setPixel(0, 0, 1.0, 0.0, 0.0, 1.0);
        // Color stream emits straight (non-premultiplied) RGB; the PDF /SMask carries alpha separately.
        self::assertSame("\xFF\x00\x00", $buf->colorBytes());
        self::assertSame("\xFF", $buf->alphaBytes());
    }

    public function testQuantizationRounding(): void
    {
        $buf = new RasterBuffer(1, 1);
        $buf->setPixel(0, 0, 0.5, 0.5, 0.5, 0.5);
        self::assertSame("\x80\x80\x80", $buf->colorBytes()); // floor(0.5*255+0.5)=128
        self::assertSame("\x80", $buf->alphaBytes());
    }

    public function testRejectsNonPositiveDimensions(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        new RasterBuffer(0, 5);
    }
}
