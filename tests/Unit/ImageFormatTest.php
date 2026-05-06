<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\ImageFormat;
use PHPUnit\Framework\TestCase;

final class ImageFormatTest extends TestCase
{
    public function testEnumCases(): void
    {
        $cases = ImageFormat::cases();
        self::assertCount(2, $cases);
        self::assertSame('JPEG', $cases[0]->name);
        self::assertSame('PNG', $cases[1]->name);
    }
}
