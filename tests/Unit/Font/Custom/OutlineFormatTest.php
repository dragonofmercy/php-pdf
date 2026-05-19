<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\OutlineFormat;
use PHPUnit\Framework\TestCase;

final class OutlineFormatTest extends TestCase
{
    public function testHasTrueTypeAndCffCases(): void
    {
        $cases = OutlineFormat::cases();
        self::assertCount(2, $cases);
        self::assertContains(OutlineFormat::TrueType, $cases);
        self::assertContains(OutlineFormat::Cff, $cases);
        self::assertNotSame(OutlineFormat::TrueType, OutlineFormat::Cff);
    }
}
