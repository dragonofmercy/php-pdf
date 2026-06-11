<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\XmpWriter;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use PHPUnit\Framework\TestCase;

final class XmpWriterPdfaTest extends TestCase
{
    public function testNoPdfaBlockByDefault(): void
    {
        $xmp = (new XmpWriter())->write(new Metadata());
        self::assertStringNotContainsString('pdfaid', $xmp);
    }

    public function testPdfaBlockForA2b(): void
    {
        $xmp = (new XmpWriter())->write(new Metadata(), PdfALevel::A2B);
        self::assertStringContainsString('xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"', $xmp);
        self::assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $xmp);
        self::assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $xmp);
    }

    public function testPdfaBlockForA2u(): void
    {
        $xmp = (new XmpWriter())->write(new Metadata(), PdfALevel::A2U);
        self::assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $xmp);
        self::assertStringContainsString('<pdfaid:conformance>U</pdfaid:conformance>', $xmp);
    }

    public function testPdfA4EmitsRevAndNoConformance(): void
    {
        $xmp = (new XmpWriter())->write(new Metadata(), PdfALevel::A4);
        self::assertStringContainsString('<pdfaid:part>4</pdfaid:part>', $xmp);
        self::assertStringContainsString('<pdfaid:rev>2020</pdfaid:rev>', $xmp);
        self::assertStringNotContainsString('pdfaid:conformance', $xmp);
    }

    public function testPdfA2bStillEmitsConformanceAndNoRev(): void
    {
        $xmp = (new XmpWriter())->write(new Metadata(), PdfALevel::A2B);
        self::assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $xmp);
        self::assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $xmp);
        self::assertStringNotContainsString('pdfaid:rev', $xmp);
    }
}
