<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\PageMargins;
use PHPUnit\Framework\TestCase;

final class FooterAutoBreakTest extends TestCase
{
    /**
     * A footer that draws a cell inside the bottom margin (below the auto-break
     * limit) must not trigger the page auto-break and append a stray blank page.
     * The footer renders deferred at output(); the auto-break must be suppressed
     * during that render, exactly as it is for the header.
     */
    public function testFooterDrawingInBottomMarginDoesNotAddPage(): void
    {
        $doc = new Document();
        $doc->setMargins(PageMargins::all(10.0));
        $doc->setAutoPageBreak(true);
        $doc->setFooter(static function (Page $p, int $n, int $total): void {
            $p->setFont(Font::helvetica(), 9);
            // Cursor at the very bottom of the media: below the auto-break limit.
            $p->setXY(10.0, $p->getPageHeight());
            $p->cell(w: 80.0, text: "Page {$n}/{$total}");
        });

        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10.0, y: 20.0, w: 80.0, h: 8.0, text: 'Body');

        $doc->output();

        self::assertSame(1, $doc->pageCount(), 'Footer must not append a blank page');
    }
}
