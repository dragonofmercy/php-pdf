<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeQrV25Test extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode/2d/qr-v25.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $itemRow = '{"sku":"PROD-12345","qty":3,"price":29.95},';
        $payload = '{"order":"ORD-2026-05-15-0001","items":[' . substr(str_repeat($itemRow, 22), 0, -1) . ']}';
        $page->barcode(
            QrCode::of($payload),
            x: 20.0, y: 20.0, w: 80.0,
        );
        return $doc->output();
    }
}
