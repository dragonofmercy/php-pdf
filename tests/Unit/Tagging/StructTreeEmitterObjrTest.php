<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use DragonOfMercy\PhpPdf\Tagging\ObjrRef;
use DragonOfMercy\PhpPdf\Tagging\StructTreeEmitter;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class StructTreeEmitterObjrTest extends TestCase
{
    /** @return array{LinkAnnotation, StructureTree} */
    private function buildLinkTree(): array
    {
        $annot = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::url('https://example.com'),
            structParentTagIndex: 0,
            contents: 'Home',
        );
        $tree = new StructureTree();
        $link = $tree->open(StructureType::Link);
        $tree->addMarkedContent(0, 0);
        $link->appendObjr(new ObjrRef($annot, pageIndex: 0));
        $tree->close();

        return [$annot, $tree];
    }

    public function testEmitsObjrKidAndSingleRefParentTreeEntry(): void
    {
        [$annot, $tree] = $this->buildLinkTree();

        /** @var \SplObjectStorage<LinkAnnotation, int> $map */
        $map = new \SplObjectStorage();
        $map[$annot] = 42;

        $pageRefs = [PdfReference::to(1, 0)];
        $result = (new StructTreeEmitter())->emit($tree, $pageRefs, $map, startObjectNumber: 50);

        $dump = '';
        foreach ($result->objects as $obj) {
            $dump .= $obj->toBytes();
        }

        // OBJR dict referencing the annotation (42) and its page (1 0 R).
        self::assertStringContainsString('/Type /OBJR', $dump);
        self::assertStringContainsString('/Obj 42 0 R', $dump);
        self::assertStringContainsString('/Pg 1 0 R', $dump);

        // Link element is numbered 53 (50 root, 51 ParentTree, 52 Document, 53 Link).
        // The MCID 0 leaf maps page 0 -> Link (53 0 R), and the annotation key
        // (pageCount 1 + tagIndex 0 = 1) -> a SINGLE ref to the Link (not an array).
        self::assertStringContainsString('/Nums [0 [53 0 R] 1 53 0 R]', $dump);

        // ParentTreeNextKey = pageCount(1) + taggedLinkCount(1) = 2.
        self::assertStringContainsString('/ParentTreeNextKey 2', $dump);
    }

    public function testMissingMapEntryThrows(): void
    {
        [, $tree] = $this->buildLinkTree();

        /** @var \SplObjectStorage<LinkAnnotation, int> $map */
        $map = new \SplObjectStorage();
        $pageRefs = [PdfReference::to(1, 0)];

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('object map');
        (new StructTreeEmitter())->emit($tree, $pageRefs, $map, startObjectNumber: 50);
    }

    public function testPageOnlyTreeEmitsNoParentTreeNextKeyAndNoObjr(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::P);
        $tree->addMarkedContent(0, 0);
        $tree->close();

        $pageRefs = [PdfReference::to(10, 0)];
        /** @var \SplObjectStorage<LinkAnnotation, int> $emptyMap */
        $emptyMap = new \SplObjectStorage();
        $result = (new StructTreeEmitter())->emit($tree, $pageRefs, $emptyMap, startObjectNumber: 50);

        $dump = '';
        foreach ($result->objects as $obj) {
            $dump .= $obj->toBytes();
        }

        self::assertStringNotContainsString('/ParentTreeNextKey', $dump);
        self::assertStringNotContainsString('/OBJR', $dump);
        self::assertStringContainsString('/Nums [0 [53 0 R]]', $dump);
    }
}
