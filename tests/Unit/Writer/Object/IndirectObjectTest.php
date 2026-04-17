<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Writer\Object;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\IndirectObject;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class IndirectObjectTest extends TestCase
{
    public function testSerializationWrapsPayload(): void
    {
        $payload = Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog'));
        $indirect = IndirectObject::of(1, 0, $payload);
        $expected = "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        self::assertSame($expected, $indirect->toBytes());
    }

    public function testReferenceReturnsMatchingPdfReference(): void
    {
        $payload = Dictionary::empty();
        $indirect = IndirectObject::of(7, 2, $payload);
        $ref = $indirect->reference();
        self::assertInstanceOf(PdfReference::class, $ref);
        self::assertSame('7 2 R', $ref->toBytes());
    }

    public function testObjectNumberAccessor(): void
    {
        $indirect = IndirectObject::of(4, 0, Dictionary::empty());
        self::assertSame(4, $indirect->objectNumber);
    }
}
