<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Tagging\MarkedContentRef;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use PHPUnit\Framework\TestCase;

final class StructElemTest extends TestCase
{
    public function testHoldsTypeAndAppendsChildren(): void
    {
        $root = new StructElem(StructureType::Document);
        $p = new StructElem(StructureType::P);
        $root->appendChild($p);
        $p->appendMarkedContent(new MarkedContentRef(0, 0));

        self::assertSame(StructureType::Document, $root->type());
        self::assertSame([$p], $root->children());
        self::assertEquals([new MarkedContentRef(0, 0)], $p->children());
    }
}
