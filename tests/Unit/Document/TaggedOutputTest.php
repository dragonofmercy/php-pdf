<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use PHPUnit\Framework\TestCase;

final class TaggedOutputTest extends TestCase
{
    public function testCatalogHasMarkInfoAndStructTreeRoot(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(w: 60, h: 8, text: 'Hello');
        $bytes = $doc->output();

        self::assertStringContainsString('/MarkInfo', $bytes);
        self::assertStringContainsString('/Marked true', $bytes);
        self::assertStringContainsString('/StructTreeRoot', $bytes);
        self::assertStringContainsString('/Lang (en-US)', $bytes);
        self::assertStringContainsString('/StructParents 0', $bytes);
        self::assertStringContainsString('/Tabs /S', $bytes);
        self::assertStringContainsString('/Type /StructTreeRoot', $bytes);
    }

    public function testStructTreeRootEmittedOnMetadataPath(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $doc->metadata()->title = 'Tagged with metadata';
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(w: 60, h: 8, text: 'Hello');
        $bytes = $doc->output();

        // The metadata path is a different code branch (outputWithMetadata)
        // that also assembles an /Info dict and XMP stream; the struct-tree
        // wiring must still appear there.
        self::assertStringContainsString('/StructTreeRoot', $bytes);
        self::assertStringContainsString('/Type /StructTreeRoot', $bytes);
        self::assertStringContainsString('/MarkInfo', $bytes);
        self::assertStringContainsString('/Marked true', $bytes);
        self::assertStringContainsString('/Lang (en-US)', $bytes);
        self::assertStringContainsString('/StructParents 0', $bytes);
        self::assertStringContainsString('/Tabs /S', $bytes);
    }
}
