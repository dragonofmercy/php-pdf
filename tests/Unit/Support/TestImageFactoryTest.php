<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Support;

use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class TestImageFactoryTest extends TestCase
{
    public function testJpegStubsHaveCorrectMagic(): void
    {
        self::assertStringStartsWith("\xFF\xD8\xFF", TestImageFactory::stubJpegRgb());
        self::assertStringStartsWith("\xFF\xD8\xFF", TestImageFactory::stubJpegGray());
        self::assertStringStartsWith("\xFF\xD8\xFF", TestImageFactory::stubJpegCmyk());
        self::assertStringEndsWith("\xFF\xD9", TestImageFactory::stubJpegRgb());
    }

    public function testPngStubsHaveCorrectSignature(): void
    {
        self::assertStringStartsWith(TestImageFactory::PNG_SIGNATURE, TestImageFactory::pngRgb());
        self::assertStringStartsWith(TestImageFactory::PNG_SIGNATURE, TestImageFactory::pngGray());
        self::assertStringStartsWith(TestImageFactory::PNG_SIGNATURE, TestImageFactory::pngPalette());
        self::assertStringStartsWith(TestImageFactory::PNG_SIGNATURE, TestImageFactory::pngRgbAlpha());
    }

    public function testPngMultiIdatHasMultipleIdatChunks(): void
    {
        $bytes = TestImageFactory::pngRgbMultiIdat(pieces: 3);
        $count = substr_count($bytes, 'IDAT');
        self::assertSame(3, $count);
    }
}
