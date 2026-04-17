<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Writer;

use PhpPdf\Writer\Object\PdfReference;
use PhpPdf\Writer\Trailer;
use PHPUnit\Framework\TestCase;

final class TrailerTest extends TestCase
{
    public function testTrailerBytes(): void
    {
        $trailer = new Trailer(size: 4, root: PdfReference::to(1, 0), xrefOffset: 250);
        $expected = "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n250\n%%EOF\n";
        self::assertSame($expected, $trailer->toBytes());
    }
}
