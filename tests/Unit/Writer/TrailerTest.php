<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Trailer;
use PHPUnit\Framework\TestCase;

final class TrailerTest extends TestCase
{
    public function testTrailerBytes(): void
    {
        $trailer = new Trailer(size: 4, root: PdfReference::to(1, 0), xrefOffset: 250);
        $expected = "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n250\n%%EOF\n";
        self::assertSame($expected, $trailer->toBytes());
    }

    public function testTrailerWithInfoReference(): void
    {
        $trailer = new Trailer(
            size: 5,
            root: PdfReference::to(1, 0),
            xrefOffset: 350,
            info: PdfReference::to(3, 0),
        );
        $expected = "trailer\n<< /Size 5 /Root 1 0 R /Info 3 0 R >>\nstartxref\n350\n%%EOF\n";
        self::assertSame($expected, $trailer->toBytes());
    }

    public function testTrailerWithDocumentId(): void
    {
        $trailer = new Trailer(
            size: 4,
            root: PdfReference::to(1, 0),
            xrefOffset: 250,
            documentId: 'abcdef0123456789abcdef0123456789',
        );
        $expected = "trailer\n<< /Size 4 /Root 1 0 R /ID [<ABCDEF0123456789ABCDEF0123456789> <ABCDEF0123456789ABCDEF0123456789>] >>\nstartxref\n250\n%%EOF\n";
        self::assertSame($expected, $trailer->toBytes());
    }

    public function testTrailerWithInfoAndId(): void
    {
        $trailer = new Trailer(
            size: 6,
            root: PdfReference::to(1, 0),
            xrefOffset: 500,
            info: PdfReference::to(3, 0),
            documentId: 'abcdef0123456789abcdef0123456789',
        );
        self::assertStringContainsString('/Info 3 0 R', $trailer->toBytes());
        self::assertStringContainsString('/ID [<ABCDEF0123456789ABCDEF0123456789> <ABCDEF0123456789ABCDEF0123456789>]', $trailer->toBytes());
    }
}
