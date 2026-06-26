<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\WebpDecoder;
use DragonOfMercy\PhpPdf\Image\WebpMetadata;
use DragonOfMercy\PhpPdf\ImageFormat;
use PHPUnit\Framework\TestCase;

final class ImageWebpDetectionTest extends TestCase
{
    public function testDetectsWebpFromMagicBytes(): void
    {
        if (!WebpDecoder::isAvailable()) {
            self::markTestSkipped('No WebP decode backend.');
        }
        $bytes = file_get_contents(__DIR__ . '/../../Golden/assets/webp-lossless-rgb-4x4.webp');
        self::assertIsString($bytes);

        $image = Image::fromBytes($bytes);

        self::assertSame(ImageFormat::WEBP, $image->format);
        self::assertSame(4, $image->width);
        self::assertSame(4, $image->height);
        self::assertInstanceOf(WebpMetadata::class, $image->metadata);
    }
}
