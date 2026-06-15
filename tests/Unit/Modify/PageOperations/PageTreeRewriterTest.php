<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Modify\PageOperations\PageRecord;
use DragonOfMercy\PhpPdf\Modify\PageOperations\PageTreeRewriter;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class PageTreeRewriterTest extends TestCase
{
    public function testReorderRewritesKidsAndCountOnFlatTree(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        $reader = PdfReader::fromBytes($doc->output());

        $pagesRootRef = $reader->catalog()->get(Name::of('Pages'));
        self::assertInstanceOf(PdfReference::class, $pagesRootRef);

        $records = [];
        for ($i = 1; $i <= 3; $i++) {
            $p = $reader->page($i);
            self::assertNotNull($p->objectNumber);
            $records[] = new PageRecord($p->objectNumber, $p->dict, $p->mediaBox, $p->cropBox, $p->rotate, $p->resources);
        }
        $finalRecords = [$records[2], $records[0]]; // page3, page1 (page2 deleted)

        $objects = (new PageTreeRewriter())->rewrite($reader, $pagesRootRef, $finalRecords, []);

        $root = null;
        foreach ($objects as $obj) {
            if ($obj->objectNumber === $pagesRootRef->objectNumber) {
                $root = $obj->dictionaryPayload();
            }
        }
        self::assertNotNull($root);
        $kids = $root->get(Name::of('Kids'));
        self::assertInstanceOf(PdfArray::class, $kids);
        $kidNums = array_map(
            static fn ($r): int => $r instanceof PdfReference ? $r->objectNumber : -1,
            $kids->elements(),
        );
        self::assertSame([$records[2]->objectNumber, $records[0]->objectNumber], $kidNums);
        $count = $root->get(Name::of('Count'));
        self::assertInstanceOf(PdfNumber::class, $count);
        self::assertSame(2, (int) $count->value());
    }
}
