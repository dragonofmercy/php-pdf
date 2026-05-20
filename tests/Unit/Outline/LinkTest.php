<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\Link;
use PHPUnit\Framework\TestCase;

final class LinkTest extends TestCase
{
    public function testUrlNamedConstructorPopulatesUrlAndLeavesDestinationNull(): void
    {
        $l = Link::url('https://example.com');
        self::assertSame('https://example.com', $l->url);
        self::assertNull($l->destination);
    }

    public function testDestinationNamedConstructorPopulatesDestinationAndLeavesUrlNull(): void
    {
        $dest = Destination::page(2);
        $l = Link::destination($dest);
        self::assertNull($l->url);
        self::assertSame($dest, $l->destination);
    }

    public function testRejectsEmptyUrl(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Link URL cannot be empty');
        Link::url('');
    }

    public function testAcceptsMailtoAndOtherSchemes(): void
    {
        $l = Link::url('mailto:test@example.com');
        self::assertSame('mailto:test@example.com', $l->url);
    }
}
