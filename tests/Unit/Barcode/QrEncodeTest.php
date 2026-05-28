<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\BarcodeKind;
use DragonOfMercy\PhpPdf\Barcode\QrCode;
use PHPUnit\Framework\TestCase;

final class QrEncodeTest extends TestCase
{
    public function testEncodeReturnsMatrix2dWithQuietZone(): void
    {
        $q = QrCode::of('hello');
        $enc = $q->encode();
        self::assertSame(BarcodeKind::MATRIX_2D, $enc->kind);
        self::assertSame([], $enc->humanTextSegments);

        // For 'hello' at EC=M, the version is V1 (21x21). +4 quiet on each side = 29.
        $matrix = $enc->modules;
        self::assertCount(29, $matrix);
        // MATRIX_2D guarantees nested rows; runtime check narrows the union for PHPStan.
        self::assertIsArray($matrix[0]);

        foreach ($matrix as $row) {
            self::assertIsArray($row);
            self::assertCount(29, $row);
        }

        // The 4-module border on each side must be all-false.
        for ($i = 0; $i < 4; $i++) {
            $row = $matrix[$i];
            self::assertIsArray($row);
            self::assertContainsOnly('bool', $row);
            self::assertNotContains(true, $row, "top quiet row $i must be all false");
        }
    }
}
