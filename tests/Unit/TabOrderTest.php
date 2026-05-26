<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\TabOrder;
use PHPUnit\Framework\TestCase;

final class TabOrderTest extends TestCase
{
    public function testCasesExist(): void
    {
        $names = array_map(static fn (TabOrder $c): string => $c->name, TabOrder::cases());
        self::assertSame(['ROW', 'COLUMN', 'STRUCTURE'], $names);
    }
}
