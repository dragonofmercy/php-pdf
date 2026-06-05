<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Tagging\StructTreeEmitter;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class StructTreeEmitterTest extends TestCase
{
    public function testEmitsRootElemsAndParentTree(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::P);
        $tree->addMarkedContent(0, 0);
        $tree->close();

        // One page, ref = 10 0 R.
        $pageRefs = [PdfReference::to(10, 0)];
        $result = (new StructTreeEmitter())->emit($tree, $pageRefs, startObjectNumber: 50);

        // StructTreeRoot is the first object, at number 50.
        self::assertSame(50, $result->structTreeRootRef->objectNumber);
        // Page 0 maps to StructParents value 0.
        self::assertSame(0, $result->pageStructParents[0]);
        // At least: StructTreeRoot + Document elem + P elem + ParentTree.
        self::assertGreaterThanOrEqual(4, count($result->objects));

        // Serialize and sanity-check key tokens are present.
        $dump = '';
        foreach ($result->objects as $obj) {
            $dump .= $obj->toBytes();
        }
        self::assertStringContainsString('/StructTreeRoot', $dump);
        self::assertStringContainsString('/S /P', $dump);
        self::assertStringContainsString('/ParentTree', $dump);
        self::assertStringContainsString('/Nums', $dump);
    }

    public function testParentTreeMapsMcidToOwningElement(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::P);
        $tree->addMarkedContent(0, 0);
        $tree->close();
        $tree->open(StructureType::P);
        $tree->addMarkedContent(0, 1);
        $tree->close();

        $pageRefs = [PdfReference::to(10, 0)];
        $result = (new StructTreeEmitter())->emit($tree, $pageRefs, startObjectNumber: 50);

        // StructTreeRoot=50, ParentTree=51, Document=52, P0=53, P1=54.
        $dump = '';
        foreach ($result->objects as $obj) {
            $dump .= $obj->toBytes();
        }
        // ParentTree /Nums for page 0 lists the two owning P elements by MCID.
        self::assertStringContainsString('/Nums [0 [53 0 R 54 0 R]]', $dump);
        // Document element is parent of the two P elements (single Document /S).
        self::assertSame(1, substr_count($dump, '/S /Document'));
        self::assertSame(2, substr_count($dump, '/S /P'));
    }
}
