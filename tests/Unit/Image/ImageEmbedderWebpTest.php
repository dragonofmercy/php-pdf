<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Image\WebpDecoder;
use PHPUnit\Framework\TestCase;

final class ImageEmbedderWebpTest extends TestCase
{
    protected function setUp(): void
    {
        if (!WebpDecoder::isAvailable()) {
            self::markTestSkipped('No WebP decode backend.');
        }
    }

    private function image(string $name): Image
    {
        return Image::fromFile(__DIR__ . '/../../Golden/assets/' . $name);
    }

    public function testOpaqueWebpEmitsOneObject(): void
    {
        $image = $this->image('webp-lossless-rgb-4x4.webp');

        self::assertSame(1, ImageEmbedder::objectCount($image));
        $objects = (new ImageEmbedder())->embed($image, 7);
        self::assertCount(1, $objects);
        self::assertSame(7, $objects[0]->objectNumber);
    }

    public function testAlphaWebpEmitsImagePlusSmask(): void
    {
        $image = $this->image('webp-lossless-alpha-4x4.webp');

        self::assertSame(2, ImageEmbedder::objectCount($image));
        $objects = (new ImageEmbedder())->embed($image, 7);
        self::assertCount(2, $objects);
        // Image object first, SMask second, consecutively numbered.
        self::assertSame(7, $objects[0]->objectNumber);
        self::assertSame(8, $objects[1]->objectNumber);
    }
}
