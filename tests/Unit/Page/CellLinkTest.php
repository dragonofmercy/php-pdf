<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
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
        self::assertSame(0, $children[1]->structParentTagIndex);
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
