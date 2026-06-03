<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Table\TableBorders;
use DragonOfMercy\PhpPdf\Table\TableResult;
use PHPUnit\Framework\TestCase;

final class TableResultTest extends TestCase
{
    public function testBordersCases(): void
    {
        self::assertCount(4, TableBorders::cases());
    }

    public function testResultFields(): void
    {
        $r = new TableResult(x: 20.0, y: 80.0, rowCount: 3, pageCount: 2);
        self::assertSame(20.0, $r->x);
        self::assertSame(80.0, $r->y);
        self::assertSame(3, $r->rowCount);
        self::assertSame(2, $r->pageCount);
    }
}
