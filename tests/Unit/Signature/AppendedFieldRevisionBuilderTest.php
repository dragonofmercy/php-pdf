<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Signature\AppendedFieldRevisionBuilder;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class AppendedFieldRevisionBuilderTest extends TestCase
{
    /**
     * @return Closure(int): IndirectObject
     */
    private function valueDictFactory(): Closure
    {
        return static fn (int $n): IndirectObject => IndirectObject::of(
            $n,
            0,
            Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Sig')),
        );
    }

    private function page(int $num, ?PdfArray $annots = null): IndirectObject
    {
        $d = Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Page'));
        if ($annots !== null) {
            $d = $d->withEntry(Name::of('Annots'), $annots);
        }
        return IndirectObject::of($num, 0, $d);
    }

    public function testStandaloneAddsAcroFormAndEvolvesContext(): void
    {
        $catalog = IndirectObject::of(1, 0, Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), PdfReference::to(2, 0)));
        $ctx = new RevisionContext(
            catalog: $catalog,
            acroForm: null,
            firstPage: $this->page(3),
            maxObjectNumber: 5,
            documentId: 'id',
        );

        $result = (new AppendedFieldRevisionBuilder())->build($ctx, $this->valueDictFactory(), 'Signature2');
        $byNum = [];
        foreach ($result['objects'] as $o) {
            $byNum[$o->objectNumber] = $o;
        }

        self::assertArrayHasKey(6, $byNum);
        self::assertArrayHasKey(7, $byNum);
        self::assertArrayHasKey(8, $byNum);
        self::assertSame(9, $result['size']);
        self::assertStringContainsString('/AcroForm 8 0 R', $byNum[1]->toBytes());
        self::assertStringContainsString('/Annots [7 0 R]', $byNum[3]->toBytes());
        self::assertStringContainsString('/FT /Sig', $byNum[7]->toBytes());
        self::assertStringContainsString('/T (Signature2)', $byNum[7]->toBytes());
        self::assertStringContainsString('/V 6 0 R', $byNum[7]->toBytes());
        self::assertStringContainsString('/Fields [7 0 R]', $byNum[8]->toBytes());

        $ctx2 = $result['context'];
        self::assertSame(8, $ctx2->maxObjectNumber);
        self::assertNotNull($ctx2->acroForm);
        self::assertSame(8, $ctx2->acroForm->objectNumber);
        self::assertSame(3, $ctx2->firstPage->objectNumber);
        self::assertSame('id', $ctx2->documentId);
    }

    public function testSecondRevisionAppendsToGrowingFieldsAndAnnots(): void
    {
        $acroForm = IndirectObject::of(8, 0, Dictionary::empty()
            ->withEntry(Name::of('Fields'), PdfArray::of(PdfReference::to(7, 0)))
            ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3)));
        $page = $this->page(3, PdfArray::of(PdfReference::to(7, 0)));
        $catalog = IndirectObject::of(1, 0, Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('AcroForm'), PdfReference::to(8, 0)));
        $ctx = new RevisionContext(
            catalog: $catalog,
            acroForm: $acroForm,
            firstPage: $page,
            maxObjectNumber: 8,
            documentId: 'id',
        );

        $result = (new AppendedFieldRevisionBuilder())->build($ctx, $this->valueDictFactory(), 'Signature3');
        $byNum = [];
        foreach ($result['objects'] as $o) {
            $byNum[$o->objectNumber] = $o;
        }

        self::assertArrayHasKey(9, $byNum);
        self::assertArrayHasKey(10, $byNum);
        self::assertArrayNotHasKey(1, $byNum);
        self::assertSame(11, $result['size']);
        self::assertStringContainsString('/Fields [7 0 R 10 0 R]', $byNum[8]->toBytes());
        self::assertStringContainsString('/Annots [7 0 R 10 0 R]', $byNum[3]->toBytes());
        self::assertSame(10, $result['context']->maxObjectNumber);
    }
}
