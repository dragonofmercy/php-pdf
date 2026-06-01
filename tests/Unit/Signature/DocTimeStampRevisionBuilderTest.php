<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\DocTimeStampRevisionBuilder;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class DocTimeStampRevisionBuilderTest extends TestCase
{
    private function page(int $num, ?PdfArray $annots = null): IndirectObject
    {
        $d = Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Page'));
        if ($annots !== null) {
            $d = $d->withEntry(Name::of('Annots'), $annots);
        }
        return IndirectObject::of($num, 0, $d);
    }

    public function testStandaloneAddsAcroFormAndPatchesCatalogAndPage(): void
    {
        $catalog = IndirectObject::of(1, 0, Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), PdfReference::to(2, 0)));
        $page = $this->page(3);
        $ctx = new RevisionContext(
            catalog: $catalog,
            acroForm: null,
            firstPage: $page,
            maxObjectNumber: 5,
            documentId: 'id',
        );

        $result = (new DocTimeStampRevisionBuilder())->build($ctx, 64);
        $byNum = [];
        foreach ($result['objects'] as $o) {
            $byNum[$o->objectNumber] = $o;
        }

        self::assertArrayHasKey(6, $byNum);
        self::assertArrayHasKey(7, $byNum);
        self::assertArrayHasKey(8, $byNum);
        self::assertSame(9, $result['size']);

        self::assertArrayHasKey(1, $byNum);
        self::assertStringContainsString('/AcroForm 8 0 R', $byNum[1]->toBytes());
        self::assertArrayHasKey(3, $byNum);
        self::assertStringContainsString('/Annots [7 0 R]', $byNum[3]->toBytes());
        self::assertStringContainsString('/Type /DocTimeStamp', $byNum[6]->toBytes());
        self::assertStringContainsString('/FT /Sig', $byNum[7]->toBytes());
        self::assertStringContainsString('/V 6 0 R', $byNum[7]->toBytes());
        self::assertStringContainsString('/Fields [7 0 R]', $byNum[8]->toBytes());
        self::assertStringContainsString('/SigFlags 3', $byNum[8]->toBytes());
    }

    public function testCombinedExtendsExistingAcroFormFieldsAndPageAnnots(): void
    {
        $acroForm = IndirectObject::of(9, 0, Dictionary::empty()
            ->withEntry(Name::of('Fields'), PdfArray::of(PdfReference::to(10, 0)))
            ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3)));
        $catalog = IndirectObject::of(1, 0, Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('AcroForm'), PdfReference::to(9, 0)));
        $page = $this->page(3, PdfArray::of(PdfReference::to(10, 0)));
        $ctx = new RevisionContext(
            catalog: $catalog,
            acroForm: $acroForm,
            firstPage: $page,
            maxObjectNumber: 11,
            documentId: 'id',
        );

        $result = (new DocTimeStampRevisionBuilder())->build($ctx, 64);
        $byNum = [];
        foreach ($result['objects'] as $o) {
            $byNum[$o->objectNumber] = $o;
        }

        self::assertArrayHasKey(12, $byNum);
        self::assertArrayHasKey(13, $byNum);
        self::assertSame(14, $result['size']);
        self::assertArrayNotHasKey(1, $byNum);
        self::assertStringContainsString('/Fields [10 0 R 13 0 R]', $byNum[9]->toBytes());
        self::assertStringContainsString('/Annots [10 0 R 13 0 R]', $byNum[3]->toBytes());
    }
}
