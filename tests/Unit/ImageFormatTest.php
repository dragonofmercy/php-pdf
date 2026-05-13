<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\ImageFormat;
use PHPUnit\Framework\TestCase;

final class ImageFormatTest extends TestCase
{
    public function testCasesExist(): void
    {
        $cases = ImageFormat::cases();
        self::assertCount(3, $cases);
        self::assertContains(ImageFormat::JPEG, $cases);
        self::assertContains(ImageFormat::PNG, $cases);
        self::assertContains(ImageFormat::SVG, $cases);
    }
}
