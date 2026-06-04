<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class CellRendererJustifyEmitTest extends TestCase
{
    public function testJustifiedCellEmitsTjArray(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12.0);
        // fixed narrow width forces a wrap; non-final lines must justify -> TJ array
        $page->cell(w: 120.0, text: 'one two three four five six seven eight nine ten', align: TextAlign::JUSTIFY);
        // Read from the raw (uncompressed) content stream before serialization
        self::assertStringContainsString('] TJ', $page->contentStream()->bytes());
    }

    public function testAutoWidthJustifyFallsBackToLeftNoTjArray(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12.0);
        // auto width (w = null) => LEFT, no TJ array
        $page->cell(text: 'short text', align: TextAlign::JUSTIFY);
        self::assertStringNotContainsString('] TJ', $page->contentStream()->bytes());
    }

    public function testSingleLineJustifyNotStretched(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12.0);
        // wide fixed width, one short line => it is the paragraph's last line => LEFT, no TJ
        $page->cell(w: 400.0, text: 'just a few words', align: TextAlign::JUSTIFY);
        self::assertStringNotContainsString('] TJ', $page->contentStream()->bytes());
    }
}
