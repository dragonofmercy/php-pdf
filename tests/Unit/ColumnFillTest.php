<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\ColumnFill;
use PHPUnit\Framework\TestCase;

final class ColumnFillTest extends TestCase
{
    public function testCases(): void
    {
        $names = array_map(static fn (ColumnFill $c): string => $c->name, ColumnFill::cases());
        self::assertSame(['SEQUENTIAL', 'BALANCED'], $names);
    }
}
