<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Modify\PageOperations\DestinationTarget;
use DragonOfMercy\PhpPdf\Modify\PageOperations\OutlinePruner;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;

final class OutlinePrunerTest extends TestCase
{
    /** @return array{reader: PdfReader, pageObjNums: list<int>} */
    private function sourceWithOutlines(): array
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        $root = $doc->outline();
        $c1 = $root->add('Chapter 1', Destination::page(0));
        $c1->add('Section 1.1', Destination::page(0));
        $root->add('Chapter 2', Destination::page(1));
        $root->add('Chapter 3', Destination::page(2));
        $reader = PdfReader::fromBytes($doc->output());
        $objNums = [];
        for ($i = 1; $i <= $reader->pageCount(); $i++) {
            $n = $reader->page($i)->objectNumber;
            self::assertNotNull($n);
            $objNums[] = $n;
        }
        return ['reader' => $reader, 'pageObjNums' => $objNums];
    }

    public function testPruningRemovesItemsTargetingDeletedPage(): void
    {
        ['reader' => $reader, 'pageObjNums' => $objNums] = $this->sourceWithOutlines();
        $deletedPageObj = $objNums[1];

        $result = (new OutlinePruner())->prune($reader, [$deletedPageObj]);

        foreach ($result->objects as $obj) {
            $dict = $obj->dictionaryPayload();
            $dest = $dict->get(Name::of('Dest')) ?? $dict->get(Name::of('A'));
            if ($dest !== null) {
                self::assertNotSame(
                    $deletedPageObj,
                    DestinationTarget::pageObjectNumber($dest, $reader),
                    'A re-emitted outline item still targets the deleted page',
                );
            }
        }
        self::assertFalse($result->outlinesEmptied);
        self::assertNotSame([], $result->objects);
    }

    public function testPruningKeepsSurvivingChildrenWhenParentRemoved(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->addPage();
        $root = $doc->outline();
        $c1 = $root->add('Chapter 1', Destination::page(1)); // targets page 2 (deleted)
        $c1->add('Section 1.1', Destination::page(0));        // targets page 1 (kept)
        $reader = PdfReader::fromBytes($doc->output());
        $page2Obj = $reader->page(2)->objectNumber;
        self::assertNotNull($page2Obj);

        $result = (new OutlinePruner())->prune($reader, [$page2Obj]);

        $hasSurvivingTitle = false;
        foreach ($result->objects as $obj) {
            if ($obj->dictionaryPayload()->get(Name::of('Title')) !== null) {
                $hasSurvivingTitle = true;
            }
        }
        self::assertTrue($hasSurvivingTitle, 'Section 1.1 must survive the removal of its parent');
        self::assertFalse($result->outlinesEmptied);
    }

    public function testNoOutlinesReturnsEmpty(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $reader = PdfReader::fromBytes($doc->output());
        $result = (new OutlinePruner())->prune($reader, [$reader->page(1)->objectNumber ?? 0]);
        self::assertSame([], $result->objects);
        self::assertFalse($result->outlinesEmptied);
    }
}
