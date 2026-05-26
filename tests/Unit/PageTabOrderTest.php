<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\TabOrder;
use PHPUnit\Framework\TestCase;

final class PageTabOrderTest extends TestCase
{
    public function testRowTabOrderEmitsTabsR(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setTabOrder(TabOrder::ROW);
        self::assertStringContainsString('/Tabs /R', $doc->output());
    }

    public function testColumnTabOrderEmitsTabsC(): void
    {
        $doc = new Document();
        $doc->addPage()->setTabOrder(TabOrder::COLUMN);
        self::assertStringContainsString('/Tabs /C', $doc->output());
    }

    public function testStructureTabOrderEmitsTabsS(): void
    {
        $doc = new Document();
        $doc->addPage()->setTabOrder(TabOrder::STRUCTURE);
        self::assertStringContainsString('/Tabs /S', $doc->output());
    }

    public function testNoTabOrderEmitsNoTabs(): void
    {
        $doc = new Document();
        $doc->addPage();
        self::assertStringNotContainsString('/Tabs', $doc->output());
    }
}
