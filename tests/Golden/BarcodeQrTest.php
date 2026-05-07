<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeQrTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-qr.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(
            QrCode::of('https://example.com'),
            x: 20.0, y: 20.0, w: 40.0,
        );
        return $doc->output();
    }
}
