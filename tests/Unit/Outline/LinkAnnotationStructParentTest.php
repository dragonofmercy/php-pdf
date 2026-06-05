<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotationEmitter;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class LinkAnnotationStructParentTest extends TestCase
{
    public function testTaggedAnnotationStampsStructParentContentsAndPrintFlag(): void
    {
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::url('https://example.com'),
            structParentTagIndex: 0,
            contents: 'Home',
        );
        $b = (new LinkAnnotationEmitter(Unit::PT))->emit($a, 842.0, [], [], 42, 'page #1', pageCount: 3);

        $bytes = $b->toBytes();
        // pageCount (3) + structParentTagIndex (0) = 3.
        self::assertStringContainsString('/StructParent 3', $bytes);
        // UTF-16BE TextString of 'Home' with BOM.
        self::assertStringContainsString('/Contents <FEFF0048006F006D0065>', $bytes);
        // Print flag (bit 3).
        self::assertStringContainsString('/F 4', $bytes);
    }

    public function testTaggedAnnotationWithoutContentsOmitsContents(): void
    {
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::url('https://example.com'),
            structParentTagIndex: 2,
            contents: null,
        );
        $bytes = (new LinkAnnotationEmitter(Unit::PT))->emit($a, 842.0, [], [], 7, 'page #1', pageCount: 5)->toBytes();

        self::assertStringContainsString('/StructParent 7', $bytes);
        self::assertStringContainsString('/F 4', $bytes);
        self::assertStringNotContainsString('/Contents', $bytes);
    }

    public function testUntaggedAnnotationIsByteIdenticalWithNoTaggingKeys(): void
    {
        $a = new LinkAnnotation(x: 0.0, y: 0.0, width: 10.0, height: 10.0, link: Link::url('https://example.com'));
        $bytes = (new LinkAnnotationEmitter(Unit::PT))->emit($a, 842.0, [], [], 1, 'page #1', pageCount: 9)->toBytes();

        self::assertStringNotContainsString('/StructParent', $bytes);
        self::assertStringNotContainsString('/Contents', $bytes);
        self::assertStringNotContainsString('/F 4', $bytes);
    }
}
