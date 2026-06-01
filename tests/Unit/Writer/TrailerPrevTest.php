<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Trailer;
use PHPUnit\Framework\TestCase;

final class TrailerPrevTest extends TestCase
{
    public function testPrevIsEmittedWhenProvided(): void
    {
        $t = new Trailer(
            size: 12,
            root: PdfReference::to(1, 0),
            xrefOffset: 9999,
            info: null,
            documentId: 'abcd',
            prev: 1234,
        );
        $bytes = $t->toBytes();
        self::assertStringContainsString('/Prev 1234', $bytes);
        self::assertStringContainsString('/Size 12', $bytes);
        self::assertStringContainsString('startxref', $bytes);
    }

    public function testPrevAbsentByDefault(): void
    {
        $t = new Trailer(size: 3, root: PdfReference::to(1, 0), xrefOffset: 10);
        self::assertStringNotContainsString('/Prev', $t->toBytes());
    }
}
