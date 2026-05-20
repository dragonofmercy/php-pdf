<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\OutlineEmitter;
use DragonOfMercy\PhpPdf\Outline\OutlineNode;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class OutlineEmitterTest extends TestCase
{
    private function emitter(Unit $unit = Unit::PT): OutlineEmitter
    {
        return new OutlineEmitter($unit);
    }

    /**
     * @param list<int> $heights
     * @return list<float>
     */
    private function heights(array $heights): array
    {
        return array_map(static fn (int $h): float => (float) $h, $heights);
    }

    public function testFlatTreeWithTwoChildrenEmitsThreeObjectsAndOpenChainedSiblings(): void
    {
        $root = OutlineNode::root();
        $root->add('Chapter 1', Destination::page(0));
        $root->add('Chapter 2', Destination::page(1));

        $pageRefs = [PdfReference::to(3, 0), PdfReference::to(5, 0)];
        $pageHeightsPt = $this->heights([842, 842]);
        $nextId = 10;

        $emit = $this->emitter()->emit($root, $pageRefs, $pageHeightsPt, $nextId, 'document outline');

        self::assertCount(3, $emit['objects']);
        self::assertSame(10, $emit['outlinesRef']->objectNumber);
        self::assertSame(13, $nextId);

        $rootBytes = $emit['objects'][0]->toBytes();
        self::assertStringContainsString('/Type /Outlines', $rootBytes);
        self::assertStringContainsString('/First 11 0 R', $rootBytes);
        self::assertStringContainsString('/Last 12 0 R', $rootBytes);
        self::assertStringContainsString('/Count 2', $rootBytes);

        $chap1Bytes = $emit['objects'][1]->toBytes();
        self::assertStringContainsString('/Title (Chapter 1)', $chap1Bytes);
        self::assertStringContainsString('/Parent 10 0 R', $chap1Bytes);
        self::assertStringContainsString('/Next 12 0 R', $chap1Bytes);
        self::assertStringNotContainsString('/Prev', $chap1Bytes);
        self::assertStringContainsString('/Dest [3 0 R /XYZ 0 842 0]', $chap1Bytes);

        $chap2Bytes = $emit['objects'][2]->toBytes();
        self::assertStringContainsString('/Title (Chapter 2)', $chap2Bytes);
        self::assertStringContainsString('/Parent 10 0 R', $chap2Bytes);
        self::assertStringContainsString('/Prev 11 0 R', $chap2Bytes);
        self::assertStringNotContainsString('/Next', $chap2Bytes);
        self::assertStringContainsString('/Dest [5 0 R /XYZ 0 842 0]', $chap2Bytes);
    }

    public function testTwoLevelTreeEmitsParentRefsAndCountAggregation(): void
    {
        $root = OutlineNode::root();
        $chap1 = $root->add('Chapter 1', Destination::page(0));
        $chap1->add('Section 1.1', Destination::page(1));
        $chap1->add('Section 1.2', Destination::page(2));

        $pageRefs = [PdfReference::to(3, 0), PdfReference::to(5, 0), PdfReference::to(7, 0)];
        $pageHeightsPt = $this->heights([842, 842, 842]);
        $nextId = 20;

        $emit = $this->emitter()->emit($root, $pageRefs, $pageHeightsPt, $nextId, 'document outline');

        self::assertCount(4, $emit['objects']);
        $rootBytes = $emit['objects'][0]->toBytes();
        self::assertStringContainsString('/Count 3', $rootBytes);
        self::assertStringContainsString('/First 21 0 R', $rootBytes);
        self::assertStringContainsString('/Last 21 0 R', $rootBytes);

        $chap1Bytes = $emit['objects'][1]->toBytes();
        self::assertStringContainsString('/First 22 0 R', $chap1Bytes);
        self::assertStringContainsString('/Last 23 0 R', $chap1Bytes);
        self::assertStringContainsString('/Count 2', $chap1Bytes);
        self::assertStringContainsString('/Parent 20 0 R', $chap1Bytes);

        $sec11Bytes = $emit['objects'][2]->toBytes();
        self::assertStringContainsString('/Parent 21 0 R', $sec11Bytes);
        self::assertStringContainsString('/Next 23 0 R', $sec11Bytes);

        $sec12Bytes = $emit['objects'][3]->toBytes();
        self::assertStringContainsString('/Parent 21 0 R', $sec12Bytes);
        self::assertStringContainsString('/Prev 22 0 R', $sec12Bytes);
    }

    public function testThreeLevelTreeWithUnevenBranchesAggregatesCountCorrectly(): void
    {
        $root = OutlineNode::root();
        $a = $root->add('A', Destination::page(0));
        $a1 = $a->add('A.1', Destination::page(1));
        $a1->add('A.1.a', Destination::page(2));
        $a->add('A.2', Destination::page(3));
        $root->add('B', Destination::page(4));

        $pageRefs = [
            PdfReference::to(3, 0), PdfReference::to(5, 0), PdfReference::to(7, 0),
            PdfReference::to(9, 0), PdfReference::to(11, 0),
        ];
        $pageHeightsPt = $this->heights([842, 842, 842, 842, 842]);
        $nextId = 30;

        $emit = $this->emitter()->emit($root, $pageRefs, $pageHeightsPt, $nextId, 'document outline');

        $rootBytes = $emit['objects'][0]->toBytes();
        self::assertStringContainsString('/Count 5', $rootBytes);

        $aBytes = $emit['objects'][1]->toBytes();
        self::assertStringContainsString('/Count 3', $aBytes);

        $a1Bytes = $emit['objects'][2]->toBytes();
        self::assertStringContainsString('/Count 1', $a1Bytes);
    }

    public function testXyzDestinationAppliesYFlipFromTargetPageHeight(): void
    {
        $root = OutlineNode::root();
        $root->add('Top of page 2', Destination::xyz(1, left: 50.0, top: 100.0, zoom: 1.0));

        $pageRefs = [PdfReference::to(3, 0), PdfReference::to(5, 0)];
        $pageHeightsPt = $this->heights([842, 600]);
        $nextId = 40;

        $emit = $this->emitter()->emit($root, $pageRefs, $pageHeightsPt, $nextId, 'document outline');
        $childBytes = $emit['objects'][1]->toBytes();
        self::assertStringContainsString('/Dest [5 0 R /XYZ 50 500 1]', $childBytes);
    }

    public function testFitDestinationSerialisesAsBareFit(): void
    {
        $root = OutlineNode::root();
        $root->add('Fit page', Destination::fit(0));
        $pageRefs = [PdfReference::to(3, 0)];
        $nextId = 50;
        $emit = $this->emitter()->emit($root, $pageRefs, $this->heights([842]), $nextId, 'document outline');
        self::assertStringContainsString('/Dest [3 0 R /Fit]', $emit['objects'][1]->toBytes());
    }

    public function testFitWidthDestinationSerialisesAsFitH(): void
    {
        $root = OutlineNode::root();
        $root->add('Fit width', Destination::fitWidth(0, top: 50.0));
        $pageRefs = [PdfReference::to(3, 0)];
        $nextId = 60;
        $emit = $this->emitter()->emit($root, $pageRefs, $this->heights([842]), $nextId, 'document outline');
        self::assertStringContainsString('/Dest [3 0 R /FitH 792]', $emit['objects'][1]->toBytes());
    }

    public function testOutOfBoundsPageIndexThrowsContextualException(): void
    {
        $root = OutlineNode::root();
        $root->add('Broken', Destination::page(7));
        $pageRefs = [PdfReference::to(3, 0)];
        $nextId = 70;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Destination references out-of-bounds page index 7 (document has 1 page(s)) for document outline');
        $this->emitter()->emit($root, $pageRefs, $this->heights([842]), $nextId, 'document outline');
    }

    public function testEmissionIsDeterministic(): void
    {
        $build = static function (): OutlineNode {
            $root = OutlineNode::root();
            $c1 = $root->add('Chapter 1', Destination::page(0));
            $c1->add('Section 1.1', Destination::page(1));
            $root->add('Chapter 2', Destination::page(2));
            return $root;
        };
        $pageRefs = [PdfReference::to(3, 0), PdfReference::to(5, 0), PdfReference::to(7, 0)];
        $heights = $this->heights([842, 842, 842]);

        $nextIdA = 100;
        $a = $this->emitter()->emit($build(), $pageRefs, $heights, $nextIdA, 'document outline');
        $nextIdB = 100;
        $b = $this->emitter()->emit($build(), $pageRefs, $heights, $nextIdB, 'document outline');

        $partsA = [];
        foreach ($a['objects'] as $obj) {
            $partsA[] = $obj->toBytes();
        }
        $partsB = [];
        foreach ($b['objects'] as $obj) {
            $partsB[] = $obj->toBytes();
        }
        self::assertSame(implode("\n", $partsA), implode("\n", $partsB));
    }
}
