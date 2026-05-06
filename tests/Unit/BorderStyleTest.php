<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\BorderStyle;
use PHPUnit\Framework\TestCase;

final class BorderStyleTest extends TestCase
{
    public function testCasesExist(): void
    {
        $cases = BorderStyle::cases();
        self::assertCount(3, $cases);
        self::assertContains(BorderStyle::SOLID, $cases);
        self::assertContains(BorderStyle::DASHED, $cases);
        self::assertContains(BorderStyle::DOTTED, $cases);
    }
}
