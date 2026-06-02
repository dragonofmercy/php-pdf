<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\PdfA\AFRelationship;
use PHPUnit\Framework\TestCase;

final class AFRelationshipTest extends TestCase
{
    public function testPdfNames(): void
    {
        self::assertSame('Data', AFRelationship::Data->pdfName());
        self::assertSame('Source', AFRelationship::Source->pdfName());
        self::assertSame('Alternative', AFRelationship::Alternative->pdfName());
        self::assertSame('Supplement', AFRelationship::Supplement->pdfName());
        self::assertSame('Unspecified', AFRelationship::Unspecified->pdfName());
    }
}
