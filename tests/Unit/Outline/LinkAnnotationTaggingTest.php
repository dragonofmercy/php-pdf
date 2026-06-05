<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use PHPUnit\Framework\TestCase;

final class LinkAnnotationTaggingTest extends TestCase
{
    public function testUntaggedAnnotationHasNullTaggingFields(): void
    {
        $a = new LinkAnnotation(x: 0.0, y: 0.0, width: 10.0, height: 10.0, link: Link::url('https://x.test'));

        self::assertNull($a->structParentTagIndex);
        self::assertNull($a->contents);
        self::assertFalse($a->isTagged());
    }

    public function testTaggedAnnotationCarriesIndexAndContents(): void
    {
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::url('https://x.test'),
            structParentTagIndex: 0,
            contents: 'Home',
        );

        self::assertSame(0, $a->structParentTagIndex);
        self::assertSame('Home', $a->contents);
        self::assertTrue($a->isTagged());
    }

    public function testStructParentKeyAddsPageCountToTagIndex(): void
    {
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::url('https://x.test'),
            structParentTagIndex: 2,
        );

        self::assertSame(5, $a->structParentKey(3));
    }

    public function testStructParentKeyThrowsForUntaggedAnnotation(): void
    {
        $a = new LinkAnnotation(x: 0.0, y: 0.0, width: 10.0, height: 10.0, link: Link::url('https://x.test'));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('structParentKey on an untagged link annotation');
        $a->structParentKey(3);
    }
}
