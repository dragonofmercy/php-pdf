<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\AztecCode;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeAztecTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-aztec.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(
            AztecCode::of('https://example.com'),
            x: 20.0, y: 20.0, w: 40.0,
        );
        return $doc->output();
    }
}
