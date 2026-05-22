<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Pdf417;

/**
 * Result of {@see Encoder::encode()}: the chosen geometry and the full
 * row-major codeword grid (length descriptor + data + pad + EC), consumed by
 * {@see Matrix}.
 *
 * @internal
 */
final readonly class EncodeResult
{
    /**
     * @param list<int> $codewords Row-major grid of length rows*columns.
     */
    public function __construct(
        public int   $columns,
        public int   $rows,
        public int   $ecLevel,
        public array $codewords,
    ) {}

    /** EC codeword count for this symbol's level: 2^(level+1). */
    public function ecCodewordCount(): int
    {
        return 1 << ($this->ecLevel + 1);
    }
}
