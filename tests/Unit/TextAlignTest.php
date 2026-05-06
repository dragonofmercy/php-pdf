<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\TextAlign;
use PHPUnit\Framework\TestCase;

final class TextAlignTest extends TestCase
{
    public function testCasesExist(): void
    {
        $cases = TextAlign::cases();
        self::assertCount(3, $cases);
        self::assertContains(TextAlign::LEFT, $cases);
        self::assertContains(TextAlign::CENTER, $cases);
        self::assertContains(TextAlign::RIGHT, $cases);
    }
}
