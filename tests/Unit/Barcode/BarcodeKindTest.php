<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\BarcodeKind;
use PHPUnit\Framework\TestCase;

final class BarcodeKindTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('linear-1d', BarcodeKind::LINEAR_1D->value);
        self::assertSame('matrix-2d', BarcodeKind::MATRIX_2D->value);
    }
}
