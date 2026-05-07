<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;

/**
 * Output of {@see Encoder::encode()}: the final interleaved codeword stream
 * that will be placed onto the QR matrix, plus the chosen version and EC level.
 *
 * @internal
 */
final readonly class EncodeResult
{
    /**
     * @param list<int> $finalCodewords
     */
    public function __construct(
        public int $version,
        public ErrorCorrection $ec,
        public array $finalCodewords,
    ) {}
}
