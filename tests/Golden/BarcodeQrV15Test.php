<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeQrV15Test extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-qr-v15.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $urlPattern = 'https://example.com/orders/2026-05-15?session=abc123&token=';
        $payload = substr(str_repeat($urlPattern, 10), 0, 400);
        $page->barcode(
            QrCode::of($payload),
            x: 20.0, y: 20.0, w: 60.0,
        );
        return $doc->output();
    }
}
