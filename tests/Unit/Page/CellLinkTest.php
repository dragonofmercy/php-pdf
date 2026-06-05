<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\ColumnFill;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Tagging\MarkedContentRef;
use DragonOfMercy\PhpPdf\Tagging\ObjrRef;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use PHPUnit\Framework\TestCase;

final class CellLinkTest extends TestCase
{
    public function testTaggingOffRegistersUntaggedAnnotationWithNoStructure(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 20, w: 50, h: 8, text: 'Visit', link: Link::url('https://x.test'));

        self::assertNull($doc->structureTree());

        $annotations = $page->getLinkAnnotations();
        self::assertCount(1, $annotations);
        $annot = $annotations[0];
        self::assertSame(10.0, $annot->x);
        self::assertSame(20.0, $annot->y);
        self::assertSame(50.0, $annot->width);
        self::assertSame(8.0, $annot->height);
        self::assertNull($annot->structParentTagIndex);
        self::assertNull($annot->contents);
        self::assertFalse($annot->isTagged());
    }

    public function testTaggingOnCreatesLinkElementWithMarkedContentThenObjr(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 20, w: 50, h: 8, text: 'the cell text', link: Link::url('https://x.test'));

        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        $link = $this->firstLink($tree->root());
        self::assertNotNull($link);

        $children = $link->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MarkedContentRef::class, $children[0]);
        self::assertInstanceOf(ObjrRef::class, $children[1]);
        self::assertSame(0, $children[0]->mcid);
        self::assertSame(0, $children[0]->pageIndex);
        self::assertSame(0, $children[1]->annotation->structParentTagIndex);
        self::assertSame(0, $children[1]->pageIndex);

        $annotations = $page->getLinkAnnotations();
        self::assertCount(1, $annotations);
        $annot = $annotations[0];
        self::assertSame($annot, $children[1]->annotation);
        self::assertSame(0, $annot->structParentTagIndex);
        self::assertSame('the cell text', $annot->contents);
        self::assertTrue($annot->isTagged());
    }

    public function testTaggingOnUsesLinkAltAsContents(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 20, w: 50, h: 8, text: 'the cell text', link: Link::url('https://x.test'), linkAlt: 'Alt');

        $annot = $page->getLinkAnnotations()[0];
        self::assertSame('Alt', $annot->contents);
        self::assertSame(0, $annot->structParentTagIndex);
    }

    public function testStructParentIndexIncrementsAcrossLinks(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 20, w: 50, h: 8, text: 'one', link: Link::url('https://a.test'), ln: NextPosition::NEWLINE);
        $page->cell(x: 10, y: 40, w: 50, h: 8, text: 'two', link: Link::url('https://b.test'));

        $annotations = $page->getLinkAnnotations();
        self::assertCount(2, $annotations);
        self::assertSame(0, $annotations[0]->structParentTagIndex);
        self::assertSame(1, $annotations[1]->structParentTagIndex);
    }

    public function testLinkAltWithoutLinkThrows(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('linkAlt');
        $page->cell(x: 10, y: 20, w: 50, h: 8, text: 'x', linkAlt: 'Alt');
    }

    public function testLinkWithoutWidthThrows(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(PdfException::class);
        $page->cell(x: 10, y: 20, h: 8, text: 'x', link: Link::url('https://x.test'));
    }

    public function testTaggedLinkSurvivesAutoPageBreakOnDestinationPage(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $doc->setAutoPageBreak(true);
        $originPage = $doc->addPage();
        $originPage->setFont(Font::helvetica(), 12);

        // Position the cursor near the bottom so the cell overflows and the
        // auto-page-break re-invokes cell() on a fresh page.
        $pageHeightMm = $originPage->getPageHeight();
        $originPage->setXY(10, $pageHeightMm - 5);

        $result = $originPage->cell(w: 50, h: 8, text: 'Visit', link: Link::url('https://x.test'), linkAlt: 'Alt');

        // The cell emitted on a different (destination) page.
        $destPage = $result->page;
        self::assertNotSame($originPage, $destPage);

        // The origin page must carry NO annotation (the link was not dropped,
        // nor double-registered): exactly one annotation, on the destination.
        self::assertCount(0, $originPage->getLinkAnnotations());

        $annotations = $destPage->getLinkAnnotations();
        self::assertCount(1, $annotations);
        $annot = $annotations[0];
        self::assertSame(0, $annot->structParentTagIndex);
        self::assertSame('Alt', $annot->contents);
        self::assertTrue($annot->isTagged());

        // A <Link> structure element holds the text MCID then the ObjrRef, both
        // bound to the destination page index.
        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        $link = $this->firstLink($tree->root());
        self::assertNotNull($link);
        $children = $link->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(MarkedContentRef::class, $children[0]);
        self::assertInstanceOf(ObjrRef::class, $children[1]);
        self::assertSame($destPage->pageIndex(), $children[0]->pageIndex);
        self::assertSame($destPage->pageIndex(), $children[1]->pageIndex);
        self::assertSame($annot, $children[1]->annotation);
    }

    public function testLinkWithMarkdownThrows(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('markdown');
        $page->cell(x: 10, y: 20, w: 50, h: 8, text: '# Title', link: Link::url('https://x.test'), markdown: true);
    }

    public function testTaggedLinkSurvivesColumnOverflow(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);

        /** @var array<int, Page> $touched */
        $touched = [];
        $touched[spl_object_id($page)] = $page;
        $page->columns(2, gap: 5, fill: ColumnFill::SEQUENTIAL, render: function (Page $p) use (&$touched): void {
            // Fill the first column to force the linked cell into the next column.
            for ($i = 0; $i < 40; $i++) {
                $res = $p->cell(h: 8, text: "Row {$i}", ln: NextPosition::BELOW);
                $touched[spl_object_id($res->page)] = $res->page;
            }
            $res = $p->cell(w: 50, h: 8, text: 'Visit', link: Link::url('https://x.test'));
            $touched[spl_object_id($res->page)] = $res->page;
        });

        // Exactly one tagged annotation total across every page touched.
        $total = 0;
        foreach ($touched as $p) {
            $total += count($p->getLinkAnnotations());
        }
        self::assertSame(1, $total);

        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        $link = $this->firstLink($tree->root());
        self::assertNotNull($link);
        self::assertCount(2, $link->children());
    }

    private function firstLink(StructElem $elem): ?StructElem
    {
        if ($elem->type() === StructureType::Link) {
            return $elem;
        }
        foreach ($elem->children() as $child) {
            if ($child instanceof StructElem) {
                $found = $this->firstLink($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}
