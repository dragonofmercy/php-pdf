<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\WebpDecoder;
use DragonOfMercy\PhpPdf\Image\WebpMetadata;
use PHPUnit\Framework\TestCase;

final class WebpMetadataTest extends TestCase
{
    private function asset(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . '/../../Golden/assets/' . $name);
        self::assertIsString($bytes);
        return $bytes;
    }

    public function testRejectsNonRiffData(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid WebP signature');
        WebpMetadata::parse('not a webp at all but long enough');
    }

    public function testRejectsRiffThatIsNotWebp(): void
    {
        // 'RIFF' + size + 'AVI ' (a valid RIFF, wrong form type).
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid WebP signature');
        WebpMetadata::parse('RIFF' . "\x10\x00\x00\x00" . 'AVI more bytes here');
    }

    public function testParsesOpaqueDimensionsWithoutAlpha(): void
    {
        if (!WebpDecoder::isAvailable()) {
            self::markTestSkipped('No WebP decode backend.');
        }
        $meta = WebpMetadata::parse($this->asset('webp-lossless-rgb-4x4.webp'));

        self::assertSame(4, $meta->width);
        self::assertSame(4, $meta->height);
        self::assertNull($meta->alphaBytes);
        // colorBytes is zlib-compressed; round-trip to confirm the payload.
        self::assertSame(str_repeat("\xFF\x00\x00", 16), gzuncompress($meta->colorBytes));
    }

    public function testParsesAlphaImageWithSmaskStream(): void
    {
        if (!WebpDecoder::isAvailable()) {
            self::markTestSkipped('No WebP decode backend.');
        }
        $meta = WebpMetadata::parse($this->asset('webp-lossless-alpha-4x4.webp'));

        self::assertSame(4, $meta->width);
        self::assertSame(4, $meta->height);
        self::assertNotNull($meta->alphaBytes);
        self::assertSame(str_repeat("\xFF\xFF\x00\x00", 4), gzuncompress($meta->alphaBytes));
    }
}
