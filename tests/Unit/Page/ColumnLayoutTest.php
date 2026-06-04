<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\ColumnFill;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Page\ColumnLayout;
use PHPUnit\Framework\TestCase;

final class ColumnLayoutTest extends TestCase
{
    public function testEqualWidthsAndPositions(): void
    {
        // content width 200, gap 10, 3 columns => colWidth = (200 - 20)/3 = 60, step = 70
        $l = ColumnLayout::compute(3, 10.0, 50.0, 30.0, 200.0, ColumnFill::SEQUENTIAL);
        self::assertSame(60.0, $l->widthPt);
        self::assertSame(70.0, $l->stepPt);
        self::assertSame(50.0, $l->leftPtForColumn(0));
        self::assertSame(120.0, $l->leftPtForColumn(1));
        self::assertSame(190.0, $l->leftPtForColumn(2));
    }

    public function testSingleColumnIsContentWidth(): void
    {
        $l = ColumnLayout::compute(1, 10.0, 50.0, 30.0, 200.0, ColumnFill::SEQUENTIAL);
        self::assertSame(200.0, $l->widthPt);
        self::assertSame(50.0, $l->leftPtForColumn(0));
    }

    public function testRejectsCountBelowOne(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('column count must be at least 1, got 0');
        ColumnLayout::compute(0, 10.0, 50.0, 30.0, 200.0, ColumnFill::SEQUENTIAL);
    }

    public function testRejectsNegativeGap(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('column gap cannot be negative, got -1');
        ColumnLayout::compute(2, -1.0, 50.0, 30.0, 200.0, ColumnFill::SEQUENTIAL);
    }

    public function testRejectsTooNarrow(): void
    {
        $this->expectException(PdfException::class);
        // 2 columns, gap 200, content 100 => colWidth negative
        ColumnLayout::compute(2, 200.0, 50.0, 30.0, 100.0, ColumnFill::SEQUENTIAL);
    }
}
