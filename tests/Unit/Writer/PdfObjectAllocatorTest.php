<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;
use PHPUnit\Framework\TestCase;

final class PdfObjectAllocatorTest extends TestCase
{
    public function testNextReturnsCurrentThenAdvancesByOne(): void
    {
        $alloc = new PdfObjectAllocator(3);
        self::assertSame(3, $alloc->next());
        self::assertSame(4, $alloc->next());
        self::assertSame(5, $alloc->next());
    }

    public function testReserveReturnsFirstThenAdvancesByCount(): void
    {
        $alloc = new PdfObjectAllocator(10);
        self::assertSame(10, $alloc->reserve(3)); // reserves 10,11,12
        self::assertSame(13, $alloc->next());
    }

    public function testPeekReturnsCurrentWithoutAdvancing(): void
    {
        $alloc = new PdfObjectAllocator(7);
        self::assertSame(7, $alloc->peek());
        self::assertSame(7, $alloc->peek());
        self::assertSame(7, $alloc->next());
        self::assertSame(8, $alloc->peek());
    }

    public function testReserveZeroDoesNotAdvance(): void
    {
        $alloc = new PdfObjectAllocator(5);
        self::assertSame(5, $alloc->reserve(0));
        self::assertSame(5, $alloc->next());
    }
}
