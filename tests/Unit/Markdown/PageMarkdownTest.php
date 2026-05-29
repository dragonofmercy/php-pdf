<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PageMarkdownTest extends TestCase
{
    public function testFlowsAcrossPagesWhenContentExceedsOnePage(): void
    {
        $doc = new Document(Unit::MM);
        $doc->setMargins(PageMargins::all(20));
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $md = str_repeat("A paragraph of text.\n\n", 400); // far more than one A4 page
        $page->markdown($md, x: 20, y: 20);
        self::assertGreaterThan(1, $doc->pageCount());
    }

    public function testReturnsSelfForChaining(): void
    {
        $doc = new Document(Unit::PT);
        $p = $doc->addPage();
        $p->setFont(Font::helvetica(), 11.0);
        self::assertSame($p, $p->markdown("# Hi", x: 10, y: 10, width: 100));
    }

    public function testNoDocumentRendersAtomicallyWithoutThrowing(): void
    {
        // A page can exist without auto-break; markdown must still render (atomic fallback).
        $doc = new Document(Unit::PT);
        $p = $doc->addPage();
        $p->setFont(Font::helvetica(), 11.0);
        $p->markdown("# Title\n\nBody.", x: 10, y: 10, width: 200);
        self::assertSame(1, $doc->pageCount());
    }

    public function testAutoBreakOffKeepsContentOnOnePageEvenWhenOverflowing(): void
    {
        // With auto-break off the whole document renders atomically (the
        // documented fallback): it may overflow but never adds a page.
        $doc = new Document(Unit::MM);
        $doc->setMargins(PageMargins::all(20));
        // auto-break NOT enabled
        $p = $doc->addPage();
        $p->setFont(Font::helvetica(), 11.0);
        $p->markdown(str_repeat("A paragraph.\n\n", 400), x: 20, y: 20);
        self::assertSame(1, $doc->pageCount());
    }

    public function testEmptyMarkdownIsNoOp(): void
    {
        $doc = new Document(Unit::MM);
        $doc->setMargins(PageMargins::all(20));
        $doc->setAutoPageBreak(true);
        $p = $doc->addPage();
        $p->setFont(Font::helvetica(), 11.0);
        self::assertSame($p, $p->markdown('', x: 20, y: 20));
        self::assertSame(1, $doc->pageCount());
    }

    public function testFlowDistributesContentAcrossEveryPage(): void
    {
        // Every paragraph must end up SOMEWHERE: decompressing the page content
        // streams and counting the marker token across all of them must recover
        // the full count (no paragraph lost at a page boundary).
        $doc = new Document(Unit::MM);
        $doc->setMargins(PageMargins::all(20));
        $doc->setAutoPageBreak(true);
        $p = $doc->addPage();
        $p->setFont(Font::helvetica(), 11.0);

        $count = 120;
        $md = '';
        for ($i = 0; $i < $count; $i++) {
            $md .= "MARKERWORD\n\n";
        }
        $p->markdown($md, x: 20, y: 20);

        $bytes = $doc->output();
        self::assertGreaterThan(1, $doc->pageCount());

        $total = 0;
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $m) > 0) {
            foreach ($m[1] as $raw) {
                $inflated = @gzuncompress($raw);
                if ($inflated !== false) {
                    $total += substr_count($inflated, 'MARKERWORD');
                }
            }
        }
        self::assertSame($count, $total);
    }
}
