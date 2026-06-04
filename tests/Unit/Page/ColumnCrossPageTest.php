<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ColumnCrossPageTest extends TestCase
{
    public function testCellFlowContinuesOnNewPageWithFont(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(0.0);
        // small page: 2 cols, each column holds ~3 cells of h=18 on a 60-tall page
        $page = $doc->addPage([160.0, 60.0]);
        $page->setFont(Font::helvetica(), 12.0);

        $lastResult = null;
        $page->columns(2, gap: 10.0, render: function (Page $p) use (&$lastResult): void {
            // fill col0 (3) + col1 (3) on page 1, then 2 more spill to page 2 col0
            for ($i = 1; $i <= 8; $i++) {
                $lastResult = $p->cell(text: 'C' . $i, h: 18.0, ln: NextPosition::BELOW);
            }
        });

        // more than one page was used
        self::assertGreaterThan(1, $doc->pageCount());
        // the last cell rendered without throwing and reported a page
        self::assertNotNull($lastResult);
    }

    public function testColumnBreakIntoNewPageThenDraw(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(0.0);
        $page = $doc->addPage([160.0, 200.0]);
        $page->setFont(Font::helvetica(), 12.0);

        $page->columns(2, gap: 10.0, render: function (Page $p): void {
            $p->cell(text: 'A', h: 18.0, ln: NextPosition::BELOW);
            $p->columnBreak();              // -> column 1
            $p->cell(text: 'B', h: 18.0, ln: NextPosition::BELOW);
            $p->columnBreak();              // last column -> new page, column 0
            $p->cell(text: 'C', h: 18.0, ln: NextPosition::BELOW);
        });

        // a second page was created and cell 'C' did not throw
        self::assertSame(2, $doc->pageCount());
    }
}
