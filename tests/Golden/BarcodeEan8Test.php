<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\Ean8;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeEan8Test extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-ean8.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(
            Ean8::of('73513537'),
            x: 20.0, y: 20.0, w: 30.0, h: 18.0,
        );
        return $doc->output();
    }
}
