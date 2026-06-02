<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevisionBuilder;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class DssRevisionBuilderTest extends TestCase
{
    public function testBuildsDssObjectsAndReemitsCatalogWithDss(): void
    {
        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), PdfReference::to(2, 0));
        $catalog = IndirectObject::of(1, 0, $catalogDict);
        $firstPage = IndirectObject::of(3, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Page')));
        $ctx = new RevisionContext(
            catalog: $catalog,
            acroForm: null,
            firstPage: $firstPage,
            maxObjectNumber: 5,
            documentId: 'ABCDEF',
        );

        $material = ValidationMaterial::of(['CERTA'], ['CRL1']);
        $built = (new DssRevisionBuilder())->build($ctx, $material);

        // cert(6) + crl(7) + DSS dict(8) + re-emitted catalog(1) = 4 objects.
        self::assertCount(4, $built['objects']);
        self::assertSame(9, $built['size']); // maxObjectNumber becomes 8, size = 9
        self::assertStringContainsString('/DSS 8 0 R', $built['context']->catalog->toBytes());
    }
}
