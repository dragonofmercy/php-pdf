<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\VerticalAlign;
use PHPUnit\Framework\TestCase;

final class PageTableHelpersTest extends TestCase
{
    public function testTextHeightGrowsWithWrapping(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 10.0);

        $oneLine = $page->tableTextHeightPt('Short', 200.0);
        $wrapped = $page->tableTextHeightPt('This is a much longer sentence that must wrap.', 60.0);
        self::assertGreaterThan($oneLine, $wrapped);
    }

    public function testDrawTableCellEmitsContent(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 10.0);

        // Should not throw and should emit drawing operators (smoke test via output()).
        $page->drawTableCell(
            50.0, 700.0, 120.0, 20.0,
            'Cell text', Border::all()->withWidth(0.5), Color::gray(240), Color::rgb(0, 0, 0),
            TextAlign::LEFT, VerticalAlign::MIDDLE, CellPadding::all(2.0),
        );
        $bytes = $doc->output();
        self::assertStringContainsString('%PDF-', $bytes);
    }
}
