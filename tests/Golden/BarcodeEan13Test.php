<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeEan13Test extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-ean13.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(
            Ean13::of('9780131103627'),
            x: 20.0, y: 20.0, w: 50.0, h: 18.0,
        );
        return $doc->output();
    }
}
