<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use PHPUnit\Framework\TestCase;

final class CellArtifactTest extends TestCase
{
    public function testCellFillAndBorderAreBracketedAsArtifactsWhenTagging(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 10, w: 40, h: 20, text: 'Hi', border: Border::all(), fill: Color::rgb(200, 200, 200));

        $bytes = $page->contentStream()->bytes();

        // Fill rectangle and the border strokes are each bracketed as artifacts.
        self::assertSame(2, substr_count($bytes, '/Artifact BDC'));
        // The text show stays inside its /P marked content, not an artifact.
        self::assertStringContainsString('/P <</MCID 0>> BDC', $bytes);
    }

    public function testCellDecorationNotBracketedWhenTaggingOff(): void
    {
        $tagged = new Document();
        $tagged->enableTagging();
        // No structure tree -> no artifact brackets. Compare to a plain document.
        $plain = new Document();
        $page = $plain->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 10, w: 40, h: 20, text: 'Hi', border: Border::all(), fill: Color::rgb(200, 200, 200));

        self::assertStringNotContainsString('/Artifact BDC', $page->contentStream()->bytes());
    }
}
