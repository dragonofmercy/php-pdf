<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\DataMatrix;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix\ReedSolomon;
use PHPUnit\Framework\TestCase;

final class ReedSolomonTest extends TestCase
{
    /**
     * ISO/IEC 16022 Annex O test vector: data "123456" encoded as ASCII pairs
     * (codewords 142, 164, 186) for the 10x10 symbol (3 data codewords + 5 EC).
     * The canonical EC output for those 3 codewords with 5 EC codewords is
     * [114, 25, 5, 88, 102].
     */
    public function testEncode10x10ReferenceVector(): void
    {
        $data = [142, 164, 186];
        $ec   = ReedSolomon::compute($data, ecCodewordCount: 5);
        self::assertSame([114, 25, 5, 88, 102], $ec);
    }

    public function testEncodeEmptyInput(): void
    {
        $ec = ReedSolomon::compute([], ecCodewordCount: 5);
        self::assertSame([0, 0, 0, 0, 0], $ec);
    }

    public function testEcCodewordsPerBlockIs62For144(): void
    {
        $ec = ReedSolomon::compute(range(0, 155), ecCodewordCount: 62);
        self::assertCount(62, $ec);
        foreach ($ec as $c) {
            self::assertGreaterThanOrEqual(0, $c);
            self::assertLessThanOrEqual(255, $c);
        }
    }
}
