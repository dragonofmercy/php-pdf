<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeDataMatrixMaxTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-datamatrix-max.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        // Long lorem-ipsum style payload pushing toward 144x144.
        $payload = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 25);
        $page->barcode(
            DataMatrix::of($payload),
            x: 20.0, y: 20.0, w: 80.0,
        );
        return $doc->output();
    }
}
