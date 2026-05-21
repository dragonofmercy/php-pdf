<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeDataMatrixC40Test extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-datamatrix-c40.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        // Uppercase + digits payload long enough to favour C40 over ASCII.
        $page->barcode(
            DataMatrix::of('PARTNO ABCDEFGHIJ 1234567890 REV3'),
            x: 20.0, y: 20.0, w: 30.0,
        );
        return $doc->output();
    }
}
