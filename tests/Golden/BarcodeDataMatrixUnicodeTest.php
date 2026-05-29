<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeDataMatrixUnicodeTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode/2d/datamatrix-unicode.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        // UTF-8 string with non-ASCII bytes (e-acute, a-grave, c-cedilla).
        $page->barcode(
            DataMatrix::of("caf\xC3\xA9 \xC3\xA0 la fran\xC3\xA7aise"),
            x: 20.0, y: 20.0, w: 30.0,
        );
        return $doc->output();
    }
}
