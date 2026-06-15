<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Modify\PageOperations\LinkAnnotationPruner;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use PHPUnit\Framework\TestCase;

final class LinkAnnotationPrunerTest extends TestCase
{
    public function testDropsGoToLinkToDeletedPageButKeepsUri(): void
    {
        $doc = new Document(Unit::PT);
        $p1 = $doc->addPage();
        $p1->link(50, 90, 200, 14, Link::url('https://example.com'));        // keep
        $p1->link(50, 130, 200, 14, Link::destination(Destination::page(1))); // -> page 2 (deleted)
        $doc->addPage();
        $reader = PdfReader::fromBytes($doc->output());

        $page1Obj = $reader->page(1)->objectNumber;
        $page2Obj = $reader->page(2)->objectNumber;
        self::assertNotNull($page1Obj);
        self::assertNotNull($page2Obj);

        $objects = (new LinkAnnotationPruner())->prune($reader, [$page1Obj], [$page2Obj]);

        self::assertCount(1, $objects, 'Page 1 has one dangling link -> re-emitted once');
        $annots = $objects[0]->dictionaryPayload()->get(Name::of('Annots'));
        self::assertInstanceOf(PdfArray::class, $annots);
        self::assertCount(1, $annots->elements(), 'Only the URI link must remain');
    }

    public function testNoDanglingLinksReEmitsNothing(): void
    {
        $doc = new Document(Unit::PT);
        $p1 = $doc->addPage();
        $p1->link(50, 90, 200, 14, Link::url('https://example.com'));
        $doc->addPage();
        $reader = PdfReader::fromBytes($doc->output());
        $page1Obj = $reader->page(1)->objectNumber;
        $page2Obj = $reader->page(2)->objectNumber;
        self::assertNotNull($page1Obj);
        self::assertNotNull($page2Obj);

        $objects = (new LinkAnnotationPruner())->prune($reader, [$page1Obj], [$page2Obj]);
        self::assertSame([], $objects);
    }
}
