<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use DragonOfMercy\PhpPdf\Tagging\ObjrRef;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use PHPUnit\Framework\TestCase;

final class ObjrRefTest extends TestCase
{
    public function testHoldsAnnotationPageAndOrdinal(): void
    {
        $annot = new LinkAnnotation(x: 0.0, y: 0.0, width: 10.0, height: 10.0, link: Link::url('https://x.test'));
        $objr = new ObjrRef($annot, pageIndex: 2);

        self::assertSame($annot, $objr->annotation);
        self::assertSame(2, $objr->pageIndex);
    }

    public function testIsFinalReadonly(): void
    {
        $r = new \ReflectionClass(ObjrRef::class);
        self::assertTrue($r->isFinal());
        self::assertTrue($r->isReadOnly());
    }

    public function testStructElemHoldsObjrChild(): void
    {
        $annot = new LinkAnnotation(x: 0.0, y: 0.0, width: 10.0, height: 10.0, link: Link::url('https://x.test'));
        $objr = new ObjrRef($annot, pageIndex: 0);
        $elem = new StructElem(StructureType::Link);
        $elem->appendObjr($objr);
        self::assertSame([$objr], $elem->children());
    }
}
