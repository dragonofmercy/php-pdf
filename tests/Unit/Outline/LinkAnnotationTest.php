<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use PHPUnit\Framework\TestCase;

final class LinkAnnotationTest extends TestCase
{
    public function testHoldsCoordinatesAndLink(): void
    {
        $link = Link::url('https://example.com');
        $a = new LinkAnnotation(x: 10.0, y: 20.0, width: 50.0, height: 12.0, link: $link);
        self::assertSame(10.0, $a->x);
        self::assertSame(20.0, $a->y);
        self::assertSame(50.0, $a->width);
        self::assertSame(12.0, $a->height);
        self::assertSame($link, $a->link);
    }

    public function testAcceptsInternalDestinationLink(): void
    {
        $link = Link::destination(Destination::page(2));
        $a = new LinkAnnotation(x: 0.0, y: 0.0, width: 1.0, height: 1.0, link: $link);
        self::assertNotNull($a->link->destination);
    }

    public function testCoordinatesAreImmutable(): void
    {
        $a = new LinkAnnotation(x: 1.0, y: 2.0, width: 3.0, height: 4.0, link: Link::url('https://x'));
        $r = new \ReflectionClass($a);
        self::assertTrue($r->isFinal());
        self::assertTrue($r->isReadOnly());
    }
}
