<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ColumnsGoldenTest extends TestCase
{
    // --- cells flowing across two columns ---

    public static function buildCells(): string
    {
        // Page 300x200 pt, margins 10pt -> content 280x180.
        // 2 cols gap 12 -> colWidth=(280-12)/2=134, col0 left=10, col1 left=156.
        // Row h=16 -> floor(180/16)=11 rows fit per column. 18 rows: ~11 in col0, ~7 in col1.
        $doc = new Document(Unit::PT);
        $doc->setMargins(10.0);
        $page = $doc->addPage([300.0, 200.0]);
        $page->setFont(Font::helvetica(), 11.0);
        $page->columns(2, gap: 12.0, render: function (Page $p): void {
            for ($i = 1; $i <= 18; $i++) {
                $p->cell(text: 'Row ' . $i . ' item', h: 16.0, border: Border::all(), ln: NextPosition::BELOW);
            }
        });
        return $doc->output();
    }

    public function testCellsMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page/columns-cells.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildCells(), 'columns-cells.pdf diverges; regenerate if intended.');
    }

    public function testCellsPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/page/columns-cells.pdf');
    }

    // --- markdown flowing across two columns, single page ---

    public static function buildMarkdown1Page(): string
    {
        // Page 300x440 pt, margins 10pt -> content 280x420.
        // 2 cols gap 12 -> colWidth=134, col0 left=10, col1 left=156.
        // Article is long enough to overflow col0 into col1 but short enough to stay on 1 page.
        $article = <<<'MD'
## Introduction

This document demonstrates multi-column markdown rendering in phppdf.
The text flows from the first column into the second column seamlessly
as content fills each column top to bottom before advancing to the next.

## Features

Column layout supports markdown content including headings, paragraphs,
and bullet lists. The cursor advances automatically when a column is full.

- Item one: fast column-aware layout engine
- Item two: automatic page break when all columns are full
- Item three: compatible with all standard markdown constructs
- Item four: headings rendered in bold at scaled font sizes
- Item five: paragraphs wrap within the column width boundary

## Details

Each column receives its own content area derived from the page margins
and the gap width. The engine positions the cursor at the top of the next
column when the current column overflows, without any caller intervention.
Inline emphasis and strong emphasis use the standard 14 font variants.

## Conclusion

The multi-column layout works correctly for single-page documents where
the total content fits within two columns on a single compact page.
MD;
        $doc = new Document(Unit::PT);
        $doc->setMargins(10.0);
        $page = $doc->addPage([300.0, 440.0]);
        $page->setFont(Font::helvetica(), 11.0);
        $page->columns(2, gap: 12.0, render: fn (Page $p) => $p->markdown($article));
        return $doc->output();
    }

    public function testMarkdown1PageMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page/columns-markdown-1page.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildMarkdown1Page(), 'columns-markdown-1page.pdf diverges; regenerate if intended.');
    }

    public function testMarkdown1PagePassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/page/columns-markdown-1page.pdf');
    }

    // --- markdown flowing across two columns over two pages ---

    public static function buildMarkdown2Pages(): string
    {
        // Page 300x400 pt, margins 10pt -> content 280x380.
        // 2 cols gap 12 -> colWidth=134, col0 left=10, col1 left=156.
        // Long article fills both columns of page 1 and overflows onto page 2.
        $section = <<<'MD'
## Section A: Background

Multi-column layout is a common typographic technique used in books,
newspapers, and technical documents. phppdf implements sequential column
fill where each column is filled top-to-bottom before the next begins.
When all columns on a page are exhausted, a new page is created and
column flow continues from the top-left column of that new page.

## Section B: Architecture

The column layout engine stores a ColumnLayout value object that tracks
the number of columns, the gap width, and the current column index. The
Page::cell() and Page::markdown() methods consult the active layout when
deciding where to wrap and when to advance to the next column or page.

- The cursor x position is set to the left edge of the current column.
- The available width is the column width, not the full page width.
- An auto-break within a column advances to the next column, not a page.

## Section C: Text Rendering

Markdown text is parsed into an AST and rendered node by node. Headings
are rendered in bold at scaled font sizes. Paragraphs wrap within the
column width. Bullet lists indent items with a leading dash character.
Inline emphasis and strong emphasis are rendered using the standard 14
font variants (italic and bold) without requiring custom font embedding.

## Section D: Page Continuation

When markdown overflows the last column of a page, phppdf automatically
adds a new page. The new page inherits the same document margins and the
column layout resets to column zero at the top margin. This allows very
long articles to span an arbitrary number of pages without any manual
page management from the caller.

## Section E: Summary

The multi-column feature is designed to be ergonomic. A single call to
columns() with a render callback is all that is needed. The callback
receives the current Page and calls the usual cell() or markdown() API.
No column-awareness is required in the callback itself; the engine handles
all the geometry automatically behind the scenes.
MD;
        $doc = new Document(Unit::PT);
        $doc->setMargins(10.0);
        $page = $doc->addPage([300.0, 400.0]);
        $page->setFont(Font::helvetica(), 11.0);
        $page->columns(2, gap: 12.0, render: fn (Page $p) => $p->markdown($section));
        return $doc->output();
    }

    public function testMarkdown2PagesMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page/columns-markdown-2pages.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildMarkdown2Pages(), 'columns-markdown-2pages.pdf diverges; regenerate if intended.');
    }

    public function testMarkdown2PagesPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/page/columns-markdown-2pages.pdf');
    }
}
