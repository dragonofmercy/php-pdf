<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeDataMatrixLargeTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode/2d/datamatrix-large.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        // Payload sized to land on a multi-region symbol (52x52 = 2x2 regions or larger).
        $page->barcode(
            DataMatrix::of(str_repeat('ABCDEFGHIJ', 18)),
            x: 20.0, y: 20.0, w: 60.0,
        );
        return $doc->output();
    }
}
