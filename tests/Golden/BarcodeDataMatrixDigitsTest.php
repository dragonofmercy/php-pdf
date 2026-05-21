<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeDataMatrixDigitsTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-datamatrix-digits.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        // Digit-pair packing: '1234567890' -> 5 ASCII pair codewords -> 12x12 symbol.
        $page->barcode(
            DataMatrix::of('1234567890'),
            x: 20.0, y: 20.0, w: 25.0,
        );
        return $doc->output();
    }
}
