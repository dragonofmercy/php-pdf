<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\AztecCode;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;

final class BarcodeAztecFullMidTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-aztec-full-mid.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(
            AztecCode::of('M1DOE/JOHN       EABCDEF DTWJFKAA 1234 123Y012C0001 100'),
            x: 20.0, y: 20.0, w: 50.0,
        );
        return $doc->output();
    }
}
