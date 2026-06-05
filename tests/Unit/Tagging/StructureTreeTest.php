<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use PHPUnit\Framework\TestCase;

final class StructureTreeTest extends TestCase
{
    public function testOpenAddCloseBuildsTree(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::P);
        $tree->addMarkedContent(0, 0);
        $tree->close();

        $root = $tree->root();
        self::assertSame(StructureType::Document, $root->type());
        $children = $root->children();
        self::assertCount(1, $children);
        self::assertInstanceOf(StructElem::class, $children[0]);
        self::assertSame(StructureType::P, $children[0]->type());
    }

    public function testCloseOnEmptyStackThrows(): void
    {
        $tree = new StructureTree();
        $this->expectException(PdfException::class);
        $tree->close();
    }

    public function testWithElementOpensAndClosesAroundBody(): void
    {
        $tree = new StructureTree();
        $tree->withElement(StructureType::Figure, function () use ($tree): void {
            $tree->addMarkedContent(0, 5);
        });
        $children = $tree->root()->children();
        self::assertInstanceOf(StructElem::class, $children[0]);
        self::assertSame(StructureType::Figure, $children[0]->type());
    }
}
