<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use PHPUnit\Framework\TestCase;

final class PageMcidTest extends TestCase
{
    public function testMcidIncrementsPerPage(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        self::assertSame(0, $page->nextMcid());
        self::assertSame(1, $page->nextMcid());
        self::assertSame(2, $page->nextMcid());
    }

    public function testMcidCounterIsIndependentPerPage(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $first = $doc->addPage();
        self::assertSame(0, $first->nextMcid());
        self::assertSame(1, $first->nextMcid());

        $second = $doc->addPage();
        self::assertSame(0, $second->nextMcid());
    }

    public function testPageIndexIsZeroBased(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $first = $doc->addPage();
        $second = $doc->addPage();
        self::assertSame(0, $first->pageIndex());
        self::assertSame(1, $second->pageIndex());
    }

    public function testPageIndexAssignedEvenWhenTaggingDisabled(): void
    {
        $doc = new Document();
        $first = $doc->addPage();
        $second = $doc->addPage();
        self::assertSame(0, $first->pageIndex());
        self::assertSame(1, $second->pageIndex());
    }
}
