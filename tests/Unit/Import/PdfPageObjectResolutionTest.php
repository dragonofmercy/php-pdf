<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Pdf;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use PHPUnit\Framework\TestCase;

final class PdfPageObjectResolutionTest extends TestCase
{
    private function threePagePdf(): string
    {
        $doc = new Document();
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        return $doc->output();
    }

    public function testPageObjectAtReturnsDistinctPagesInOrder(): void
    {
        $pdf = Pdf::fromBytes($this->threePagePdf());
        $m = new \ReflectionMethod($pdf, 'pageObjectAt');
        $p0 = $m->invoke($pdf, 0);
        $p2 = $m->invoke($pdf, 2);
        self::assertInstanceOf(IndirectObject::class, $p0);
        self::assertInstanceOf(IndirectObject::class, $p2);
        self::assertNotSame($p0->objectNumber, $p2->objectNumber);
    }

    public function testPageObjectAtOutOfRangeThrows(): void
    {
        $pdf = Pdf::fromBytes($this->threePagePdf());
        $m = new \ReflectionMethod($pdf, 'pageObjectAt');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~page 5.*3 pages~');
        $m->invoke($pdf, 5);
    }

    public function testSourceDocumentIdIsStableHex(): void
    {
        $bytes = $this->threePagePdf();
        $pdf = Pdf::fromBytes($bytes);
        $m = new \ReflectionMethod($pdf, 'sourceDocumentId');
        $id = $m->invoke($pdf);
        self::assertIsString($id);
        self::assertMatchesRegularExpression('~^[0-9A-Fa-f]+$~', $id);
        $pdf2 = Pdf::fromBytes($bytes);
        $id2 = (new \ReflectionMethod($pdf2, 'sourceDocumentId'))->invoke($pdf2);
        self::assertSame($id, $id2);
    }
}
