<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image\WebpDecoder;
use PHPUnit\Framework\TestCase;

final class WebpDecoderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!WebpDecoder::isAvailable()) {
            self::markTestSkipped('No WebP decode backend (ext-imagick or ext-gd with WebP).');
        }
    }

    private function asset(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . '/../../Golden/assets/' . $name);
        self::assertIsString($bytes);
        return $bytes;
    }

    public function testDecodesLosslessOpaqueToPackedRgbWithoutAlpha(): void
    {
        $result = WebpDecoder::decode($this->asset('webp-lossless-rgb-4x4.webp'));

        self::assertSame(4, $result['width']);
        self::assertSame(4, $result['height']);
        self::assertSame(48, strlen($result['rgb']));
        self::assertSame(str_repeat("\xFF\x00\x00", 16), $result['rgb']);
        self::assertNull($result['alpha']);
    }

    public function testDecodesBinaryAlphaToOpacityBuffer(): void
    {
        $result = WebpDecoder::decode($this->asset('webp-lossless-alpha-4x4.webp'));

        self::assertSame(4, $result['width']);
        self::assertSame(4, $result['height']);
        self::assertNotNull($result['alpha']);
        self::assertSame(16, strlen($result['alpha']));
        // Each row: cols 0-1 opaque (255), cols 2-3 transparent (0).
        self::assertSame(str_repeat("\xFF\xFF\x00\x00", 4), $result['alpha']);
        // First pixel RGB is blue.
        self::assertSame("\x00\x00\xFF", substr($result['rgb'], 0, 3));
    }

    public function testDecodesLossyToCorrectDimensions(): void
    {
        $result = WebpDecoder::decode($this->asset('webp-lossy-rgb-8x8.webp'));

        self::assertSame(8, $result['width']);
        self::assertSame(8, $result['height']);
        self::assertSame(192, strlen($result['rgb']));
        self::assertNull($result['alpha']);
    }

    public function testDecodesAnimatedFirstFrame(): void
    {
        $result = WebpDecoder::decode($this->asset('webp-animated-4x4.webp'));

        self::assertSame(4, $result['width']);
        self::assertSame(4, $result['height']);
        self::assertSame(48, strlen($result['rgb']));
        self::assertNull($result['alpha']);
    }
}
