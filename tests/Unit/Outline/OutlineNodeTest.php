<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\OutlineNode;
use PHPUnit\Framework\TestCase;

final class OutlineNodeTest extends TestCase
{
    public function testRootHasNoTitleNoDestinationNoParent(): void
    {
        $root = OutlineNode::root();
        self::assertNull($root->title());
        self::assertNull($root->destination());
        self::assertNull($root->parent());
        self::assertTrue($root->isRoot());
        self::assertFalse($root->hasChildren());
        self::assertSame([], $root->children());
    }

    public function testAddCreatesChildAndReturnsItForChaining(): void
    {
        $root = OutlineNode::root();
        $chap1 = $root->add('Chapter 1', Destination::page(0));
        self::assertNotSame($root, $chap1);
        self::assertSame('Chapter 1', $chap1->title());
        self::assertNotNull($chap1->destination());
        self::assertSame(0, $chap1->destination()->pageIndex);
        self::assertSame($root, $chap1->parent());
        self::assertFalse($chap1->isRoot());
        self::assertTrue($root->hasChildren());
        self::assertSame([$chap1], $root->children());
    }

    public function testSiblingsArePreservedInInsertionOrder(): void
    {
        $root = OutlineNode::root();
        $a = $root->add('A', Destination::page(0));
        $b = $root->add('B', Destination::page(1));
        $c = $root->add('C', Destination::page(2));
        self::assertSame([$a, $b, $c], $root->children());
    }

    public function testTwoLevelHierarchy(): void
    {
        $root = OutlineNode::root();
        $chap1 = $root->add('Chapter 1', Destination::page(0));
        $sec11 = $chap1->add('Section 1.1', Destination::page(1));
        $sec12 = $chap1->add('Section 1.2', Destination::page(2));
        self::assertSame([$chap1], $root->children());
        self::assertSame([$sec11, $sec12], $chap1->children());
        self::assertSame($chap1, $sec11->parent());
        self::assertSame($chap1, $sec12->parent());
    }

    public function testThreeLevelHierarchy(): void
    {
        $root = OutlineNode::root();
        $a = $root->add('A', Destination::page(0));
        $a1 = $a->add('A.1', Destination::page(1));
        $a1a = $a1->add('A.1.a', Destination::page(2));
        self::assertSame($a, $a1->parent());
        self::assertSame($a1, $a1a->parent());
        self::assertFalse($a1->isRoot());
        self::assertTrue($a1->hasChildren());
        self::assertSame([$a1a], $a1->children());
    }

    public function testRejectsEmptyTitle(): void
    {
        $root = OutlineNode::root();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Outline node title cannot be empty');
        $root->add('', Destination::page(0));
    }

    public function testRejectsWhitespaceOnlyTitle(): void
    {
        $root = OutlineNode::root();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Outline node title cannot be empty');
        $root->add("   \t\n", Destination::page(0));
    }

    public function testChildrenReturnTypeIsList(): void
    {
        $root = OutlineNode::root();
        $root->add('A', Destination::page(0));
        $root->add('B', Destination::page(1));
        $children = $root->children();
        self::assertSame([0, 1], array_keys($children));
    }
}
