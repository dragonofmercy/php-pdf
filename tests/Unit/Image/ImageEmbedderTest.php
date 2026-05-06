<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class ImageEmbedderTest extends TestCase
{
    public function testEmbedsJpegRgb(): void
    {
        $img = Image::fromBytes(TestImageFactory::stubJpegRgb(width: 100, height: 50));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 10);
        self::assertCount(1, $objects);
        self::assertSame(10, $objects[0]->objectNumber);
        $bytes = $objects[0]->toBytes();
        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertStringContainsString('/Width 100', $bytes);
        self::assertStringContainsString('/Height 50', $bytes);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
        self::assertStringContainsString('/BitsPerComponent 8', $bytes);
        self::assertStringContainsString('/Filter /DCTDecode', $bytes);
        // The raw JPEG bytes appear inside the stream segment.
        self::assertStringContainsString("\xFF\xD8\xFF", $bytes);
    }

    public function testEmbedsJpegGrayscale(): void
    {
        $img = Image::fromBytes(TestImageFactory::stubJpegGray(width: 8, height: 8));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 1);
        self::assertCount(1, $objects);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $objects[0]->toBytes());
    }

    public function testEmbedsJpegCmykWithDecode(): void
    {
        $img = Image::fromBytes(TestImageFactory::stubJpegCmyk(width: 8, height: 8));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 1);
        self::assertCount(1, $objects);
        $bytes = $objects[0]->toBytes();
        self::assertStringContainsString('/ColorSpace /DeviceCMYK', $bytes);
        self::assertStringContainsString('/Decode [1 0 1 0 1 0 1 0]', $bytes);
    }

    public function testJpegCmykDictionaryEntriesInCorrectOrder(): void
    {
        $img = Image::fromBytes(TestImageFactory::stubJpegCmyk(width: 8, height: 8));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 1);
        $bytes = $objects[0]->toBytes();
        // Decode must appear before Length in the dictionary.
        $decodePos = strpos($bytes, '/Decode');
        $lengthPos = strpos($bytes, '/Length');
        self::assertNotFalse($decodePos);
        self::assertNotFalse($lengthPos);
        self::assertLessThan($lengthPos, $decodePos, '/Decode must precede /Length per PDF convention');
    }

    public function testEmbedsOpaquePngRgb(): void
    {
        $img = Image::fromBytes(TestImageFactory::pngRgb(width: 16, height: 8));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 5);
        self::assertCount(1, $objects);
        $bytes = $objects[0]->toBytes();
        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertStringContainsString('/Width 16', $bytes);
        self::assertStringContainsString('/Height 8', $bytes);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
        self::assertStringContainsString('/BitsPerComponent 8', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
        self::assertStringContainsString('/Predictor 15', $bytes);
        self::assertStringContainsString('/Columns 16', $bytes);
        self::assertStringContainsString('/Colors 3', $bytes);
    }

    public function testEmbedsOpaquePngGrayscale(): void
    {
        $img = Image::fromBytes(TestImageFactory::pngGray(width: 4, height: 4));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 1);
        self::assertCount(1, $objects);
        $bytes = $objects[0]->toBytes();
        self::assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
        self::assertStringContainsString('/Colors 1', $bytes);
    }

    public function testEmbedsPalettePng(): void
    {
        $img = Image::fromBytes(TestImageFactory::pngPalette(width: 4, height: 4));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 1);
        self::assertCount(1, $objects);
        $bytes = $objects[0]->toBytes();
        // /ColorSpace [/Indexed /DeviceRGB 1 <palette-hex>]
        self::assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB 1', $bytes);
        // The 2-color palette (FF0000 00FF00) appears as a hex literal somewhere in the dict.
        self::assertStringContainsString('<FF000000FF00>', $bytes);
        self::assertStringContainsString('/Colors 1', $bytes);
    }

    public function testEmbedsPngRgbAlphaWithSmask(): void
    {
        $img = Image::fromBytes(TestImageFactory::pngRgbAlpha(width: 4, height: 4));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 7);
        self::assertCount(2, $objects);
        self::assertSame(7, $objects[0]->objectNumber);
        self::assertSame(8, $objects[1]->objectNumber);

        $imageBytes = $objects[0]->toBytes();
        self::assertStringContainsString('/SMask 8 0 R', $imageBytes);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $imageBytes);

        $smaskBytes = $objects[1]->toBytes();
        self::assertStringContainsString('/Subtype /Image', $smaskBytes);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $smaskBytes);
        self::assertStringContainsString('/Width 4', $smaskBytes);
        self::assertStringContainsString('/Height 4', $smaskBytes);
        self::assertStringContainsString('/Filter /FlateDecode', $smaskBytes);
    }

    public function testEmbedsPngGrayAlphaWithSmask(): void
    {
        $img = Image::fromBytes(TestImageFactory::pngGrayAlpha(width: 2, height: 2));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 1);
        self::assertCount(2, $objects);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $objects[0]->toBytes());
        self::assertStringContainsString('/SMask 2 0 R', $objects[0]->toBytes());
    }

    public function testEmbedsPalettePngWithTrnsHasSmask(): void
    {
        $img = Image::fromBytes(TestImageFactory::pngPaletteWithTrns(width: 2, height: 2));
        $objects = (new ImageEmbedder())->embed($img, firstObjectNumber: 1);
        self::assertCount(2, $objects);
        self::assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB', $objects[0]->toBytes());
        self::assertStringContainsString('/SMask 2 0 R', $objects[0]->toBytes());
        self::assertStringContainsString('/ColorSpace /DeviceGray', $objects[1]->toBytes());
    }
}
