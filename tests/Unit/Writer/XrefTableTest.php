<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Writer;

use PhpPdf\Writer\XrefTable;
use PHPUnit\Framework\TestCase;

final class XrefTableTest extends TestCase
{
    public function testEmptyTableStillEmitsFreeHead(): void
    {
        $expected = "xref\n0 1\n0000000000 65535 f \n";
        self::assertSame($expected, (new XrefTable())->toBytes());
    }

    public function testSingleEntry(): void
    {
        $table = new XrefTable();
        $table->recordOffset(1, 15);
        $expected = "xref\n0 2\n0000000000 65535 f \n0000000015 00000 n \n";
        self::assertSame($expected, $table->toBytes());
    }

    public function testThreeEntriesContiguous(): void
    {
        $table = new XrefTable();
        $table->recordOffset(1, 15);
        $table->recordOffset(2, 66);
        $table->recordOffset(3, 120);
        $expected = "xref\n0 4\n0000000000 65535 f \n"
            . "0000000015 00000 n \n"
            . "0000000066 00000 n \n"
            . "0000000120 00000 n \n";
        self::assertSame($expected, $table->toBytes());
    }

    public function testSizeExposesObjectCountIncludingFreeHead(): void
    {
        $table = new XrefTable();
        $table->recordOffset(1, 15);
        $table->recordOffset(2, 66);
        self::assertSame(3, $table->size());
    }
}
