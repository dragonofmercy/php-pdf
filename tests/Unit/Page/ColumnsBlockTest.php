<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\ColumnFill;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ColumnsBlockTest extends TestCase
{
    private function page(): Page
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(0.0);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12.0);
        return $page;
    }

    public function testBlockActivatesAndDeactivatesLayout(): void
    {
        $page = $this->page();
        $doc = $page->document();
        self::assertNotNull($doc);
        $seenInside = null;
        $page->columns(2, gap: 10.0, render: function (Page $p) use (&$seenInside): void {
            $inner = $p->document();
            self::assertNotNull($inner);
            $seenInside = $inner->columnLayout();
        });
        self::assertNotNull($seenInside, 'layout active inside the block');
        self::assertNull($doc->columnLayout(), 'layout cleared after the block');
    }

    public function testNestingThrows(): void
    {
        $page = $this->page();
        $this->expectException(PdfException::class);
        $page->columns(2, gap: 10.0, render: function (Page $p): void {
            $p->columns(2, gap: 10.0, render: static function (): void {});
        });
    }

    public function testImageInsideThrows(): void
    {
        $page = $this->page();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('not supported inside a columns() block');
        $page->columns(2, gap: 10.0, render: function (Page $p): void {
            $p->image(__DIR__ . '/nope.png');
        });
    }

    public function testColumnBreakOutsideBlockThrows(): void
    {
        $page = $this->page();
        $this->expectException(PdfException::class);
        $page->columnBreak();
    }

    public function testBalancedThrowsNotImplemented(): void
    {
        $page = $this->page();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('not yet implemented');
        $page->columns(2, gap: 10.0, fill: ColumnFill::BALANCED, render: static function (): void {});
    }

    public function testLayoutClearedEvenWhenCallbackThrows(): void
    {
        $page = $this->page();
        try {
            $page->columns(2, gap: 10.0, render: static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $doc = $page->document();
        self::assertNotNull($doc);
        self::assertNull($doc->columnLayout());
    }
}
