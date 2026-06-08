<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Text;

use DragonOfMercy\PhpPdf\Text\Direction;
use PHPUnit\Framework\TestCase;

final class DirectionTest extends TestCase
{
    public function testCasesExist(): void
    {
        self::assertCount(3, Direction::cases());
        self::assertSame('LTR', Direction::LTR->name);
        self::assertSame('RTL', Direction::RTL->name);
        self::assertSame('AUTO', Direction::AUTO->name);
    }
}
