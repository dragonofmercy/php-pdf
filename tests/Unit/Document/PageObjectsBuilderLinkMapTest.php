<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document\PageObjectsBuilder;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotationEmitter;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;
use PHPUnit\Framework\TestCase;

final class PageObjectsBuilderLinkMapTest extends TestCase
{
    public function testBuildRecordsLinkAnnotationToObjectNumberMap(): void
    {
        $page = new Page(595.0, 842.0, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->link(10.0, 10.0, 50.0, 10.0, Link::url('https://example.com'));
        $annots = $page->getLinkAnnotations();
        self::assertCount(1, $annots);
        $annot = $annots[0];

        $allocator = new PdfObjectAllocator(100);
        $builder = new PageObjectsBuilder(
            allocator: $allocator,
            fontRegistry: new FontRegistry(),
            fontResolver: null,
            linkAnnotationEmitter: new LinkAnnotationEmitter(Unit::MM),
            pagesRef: PdfReference::to(2, 0),
            fontRefs: [],
            customRefs: [],
            imageRefs: [],
        );

        $pageRefs = [PdfReference::to(3, 0)];
        $result = $builder->build(
            [[$page, 3, 4]],
            $pageRefs,
            [842.0],
        );

        self::assertArrayHasKey('linkAnnotationMap', $result);
        $map = $result['linkAnnotationMap'];
        self::assertTrue(isset($map[$annot]));
        // First allocated id after firstObjectNumber 100.
        self::assertSame(100, $map[$annot]);
    }
}
