<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Modify\EditRevisionBuilder;
use DragonOfMercy\PhpPdf\PdfEditor;
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

    private function builderFor(PdfEditor $pdf): EditRevisionBuilder
    {
        $builder = (new \ReflectionMethod($pdf, 'revisionBuilder'))->invoke($pdf);
        self::assertInstanceOf(EditRevisionBuilder::class, $builder);
        return $builder;
    }

    public function testPageObjectAtReturnsDistinctPagesInOrder(): void
    {
        $builder = $this->builderFor(PdfEditor::fromBytes($this->threePagePdf()));
        $m = new \ReflectionMethod($builder, 'pageObjectAt');
        $p0 = $m->invoke($builder, 0);
        $p2 = $m->invoke($builder, 2);
        self::assertInstanceOf(IndirectObject::class, $p0);
        self::assertInstanceOf(IndirectObject::class, $p2);
        self::assertNotSame($p0->objectNumber, $p2->objectNumber);
    }

    public function testPageObjectAtOutOfRangeThrows(): void
    {
        $builder = $this->builderFor(PdfEditor::fromBytes($this->threePagePdf()));
        $m = new \ReflectionMethod($builder, 'pageObjectAt');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~page 5.*3 pages~');
        $m->invoke($builder, 5);
    }

    public function testSourceDocumentIdIsStableHex(): void
    {
        $bytes = $this->threePagePdf();
        $builder = $this->builderFor(PdfEditor::fromBytes($bytes));
        $id = (new \ReflectionMethod($builder, 'sourceDocumentId'))->invoke($builder);
        self::assertIsString($id);
        self::assertMatchesRegularExpression('~^[0-9A-Fa-f]+$~', $id);
        $builder2 = $this->builderFor(PdfEditor::fromBytes($bytes));
        $id2 = (new \ReflectionMethod($builder2, 'sourceDocumentId'))->invoke($builder2);
        self::assertSame($id, $id2);
    }
}
