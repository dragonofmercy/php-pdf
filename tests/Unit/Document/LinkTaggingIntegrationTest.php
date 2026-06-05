<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Outline\Link;
use PHPUnit\Framework\TestCase;

final class LinkTaggingIntegrationTest extends TestCase
{
    public function testTaggedCellLinkEmitsObjrStructParentAndNextKey(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(w: 80.0, h: 10.0, text: 'Visit example.com', link: Link::url('https://example.com'), linkAlt: 'Example home page');

        $bytes = $doc->output();

        self::assertStringContainsString('/OBJR', $bytes);
        self::assertStringContainsString('/StructParent', $bytes);
        self::assertStringContainsString('/ParentTreeNextKey', $bytes);
        // The /Contents alternate is carried on the annotation.
        self::assertStringContainsString('/Contents', $bytes);
    }
}
