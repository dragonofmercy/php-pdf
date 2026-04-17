<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Writer\Object;

use PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class PdfReferenceTest extends TestCase
{
    public function testGenerationZeroIsSerialized(): void
    {
        self::assertSame('1 0 R', PdfReference::to(1, 0)->toBytes());
    }

    public function testHigherGeneration(): void
    {
        self::assertSame('12 5 R', PdfReference::to(12, 5)->toBytes());
    }
}
