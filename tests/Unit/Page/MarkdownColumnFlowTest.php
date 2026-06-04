<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class MarkdownColumnFlowTest extends TestCase
{
    /**
     * With a short page (80 pt), 2 columns (gap=20), and enough markdown text,
     * content overflows from column 0 into column 1 on the same page.
     * Column geometry (Unit::PT, margins=0, pageWidth=300):
     *   widthPt = (300 - 20) / 2 = 140
     *   stepPt  = 140 + 20      = 160
     *   col0 left = 0, col1 left = 160
     *
     * The text matrix format (with Y-flip CTM) is "1 0 0 -1 {x} {y} Tm".
     * Column-1 runs must emit x=160 in the Tm operator on page 1's content stream.
     */
    public function testMarkdownInColumnsShiftsIntoSecondColumnSamePage(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(0.0);
        // Short page (80 pt high): with 12pt font at line-height ~14.4pt, ~5 lines
        // fill the column, so remaining paragraphs must spill to column 1.
        $page = $doc->addPage([300.0, 80.0]);
        $page->setFont(Font::helvetica(), 12.0);

        $long = str_repeat("Para line here.\n\n", 12);
        $page->columns(2, gap: 20.0, render: function (Page $p) use ($long): void {
            $p->markdown($long);
        });

        // Page 1's content stream must contain text placed at x=160 (column 1).
        // The Tm operator with Y-flip uses "1 0 0 -1 {x} {y} Tm" format.
        $bytes = $page->contentStream()->bytes();
        self::assertMatchesRegularExpression('/1 0 0 -1 160 \S+ Tm/', $bytes, 'column-1 text must be placed at x=160 (stepPt=160) in page 1 content stream');

        // Column overflow stayed on the same first page (then after column 1 fills
        // up, an additional page may be used for overflow beyond both columns).
        // The key invariant: column-1 content is on page 1, not a new page early.
        self::assertStringContainsString('1 0 0 -1 160', $bytes, 'column-1 x-shift of 160 must appear in page 1 content stream');
    }

    /**
     * Markdown without a columns() context must not apply any x-shift: emitX
     * adds xShiftPt=0.0 outside columns so all horizontal positions are
     * identical to pre-column-flow code.
     */
    public function testMarkdownOutsideColumnsIsUnaffected(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(0.0);
        $page = $doc->addPage([300.0, 800.0]);
        $page->setFont(Font::helvetica(), 12.0);

        $md = "Hello world.\n\nSecond paragraph.\n";
        $page->markdown($md);

        // Text starts at x=0 (left margin=0, column 0 origin = 0).
        // "1 0 0 -1 0 {y} Tm" for the first text run.
        $bytes = $page->contentStream()->bytes();
        self::assertMatchesRegularExpression('/1 0 0 -1 0 \S+ Tm/', $bytes, 'without columns, text x must be 0 (no x-shift)');
    }
}
