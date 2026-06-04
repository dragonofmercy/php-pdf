<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class CellColumnFlowTest extends TestCase
{
    public function testCellOverflowMovesToNextColumnNotNewPage(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(0.0);
        // page 200 wide x 60 tall; 2 columns gap 20 => colWidth=90, step=110
        $page = $doc->addPage([200.0, 60.0]);
        $page->setFont(Font::helvetica(), 12.0);

        $lefts = [];
        $page->columns(2, gap: 20.0, render: function (Page $p) use (&$lefts): void {
            // each cell h=20; column inner height 60 => 3 cells fill a column, the
            // 4th overflows into column 1
            for ($i = 0; $i < 4; $i++) {
                $r = $p->cell(text: 'X', h: 20.0, ln: NextPosition::BELOW);
                $lefts[] = $r->x - $r->effectiveWidth; // left edge of this cell
            }
        });

        self::assertCount(4, $lefts);
        // first 3 cells in column 0 (left ~0), 4th cell in column 1 (left ~110)
        self::assertEqualsWithDelta(0.0, $lefts[0], 0.01);
        self::assertEqualsWithDelta(110.0, $lefts[3], 0.01, '4th cell spilled into column 1, not a new page');
        // and it stayed on the SAME page (only one page total)
        self::assertSame(1, $doc->pageCount());
    }

    public function testCellUsesColumnWidthForWrapping(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(0.0);
        $page = $doc->addPage([200.0, 400.0]);
        $page->setFont(Font::helvetica(), 12.0);
        $w = null;
        $page->columns(2, gap: 20.0, render: function (Page $p) use (&$w): void {
            $r = $p->cell(text: 'A', h: 10.0); // no w => column width
            $w = $r->effectiveWidth;
        });
        self::assertEqualsWithDelta(90.0, $w, 0.01, 'cell defaults to the column width (90)');
    }
}
