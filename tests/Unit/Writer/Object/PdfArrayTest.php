<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use PHPUnit\Framework\TestCase;

final class PdfArrayTest extends TestCase
{
    public function testEmptyArray(): void
    {
        self::assertSame('[]', PdfArray::of()->toBytes());
    }

    public function testTwoNames(): void
    {
        $array = PdfArray::of(Name::of('Type'), Name::of('Page'));
        self::assertSame('[/Type /Page]', $array->toBytes());
    }

    public function testMixedTypes(): void
    {
        $array = PdfArray::of(
            PdfNumber::ofInt(0),
            PdfNumber::ofInt(0),
            PdfNumber::ofFloat(595.28),
            PdfNumber::ofFloat(841.89),
        );
        self::assertSame('[0 0 595.28 841.89]', $array->toBytes());
    }

    public function testNestedArray(): void
    {
        $inner = PdfArray::of(Name::of('a'));
        $outer = PdfArray::of($inner, Name::of('b'));
        self::assertSame('[[/a] /b]', $outer->toBytes());
    }
}
