<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\AztecCode;
use DragonOfMercy\PhpPdf\Barcode\AztecEc;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeAztecEcHighTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-aztec-ec-high.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(
            AztecCode::of('SHORT', AztecEc::HIGH)->withColor(Color::rgb(192, 0, 0)),
            x: 20.0, y: 20.0, w: 30.0,
        );
        return $doc->output();
    }
}
