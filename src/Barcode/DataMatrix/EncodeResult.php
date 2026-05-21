<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

/**
 * Result of {@see Encoder::encode()}: the chosen symbol and the
 * final interleaved data + EC codeword sequence ready for placement
 * by {@see Matrix}.
 *
 * @internal
 */
final readonly class EncodeResult
{
    /**
     * @param Symbol    $symbol          The chosen ECC200 square.
     * @param list<int> $finalCodewords  Data + EC codewords in placement order
     *                                   (already interleaved per ISO 5.8.4).
     */
    public function __construct(
        public Symbol $symbol,
        public array  $finalCodewords,
    ) {}
}
