<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image\PngColorType;
use PHPUnit\Framework\TestCase;

final class PngColorTypeTest extends TestCase
{
    public function testIhdrValues(): void
    {
        self::assertSame(0, PngColorType::GRAY->value);
        self::assertSame(2, PngColorType::RGB->value);
        self::assertSame(3, PngColorType::PALETTE->value);
        self::assertSame(4, PngColorType::GRAY_ALPHA->value);
        self::assertSame(6, PngColorType::RGB_ALPHA->value);
        self::assertSame(PngColorType::RGB, PngColorType::from(2));
        self::assertNull(PngColorType::tryFrom(99));
    }
}
