<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

/**
 * Aztec encoder output: everything Matrix needs to draw the symbol.
 *
 * @internal
 */
final readonly class EncodeResult
{
    /**
     * @param bool      $compact       true = Compact (1-4 layers), false = Full Range (1-32 layers)
     * @param int       $layers        number of data layers (1..4 if compact, 1..32 otherwise)
     * @param int       $codewordBits  bits per codeword (6, 8, 10, or 12)
     * @param list<int> $dataCodewords encoded user data codewords after bit-stuffing
     * @param list<int> $ecCodewords   Reed-Solomon EC codewords
     */
    public function __construct(
        public bool $compact,
        public int $layers,
        public int $codewordBits,
        public array $dataCodewords,
        public array $ecCodewords,
    ) {}

    /** Total codewords = data + EC. */
    public function totalCodewords(): int
    {
        return count($this->dataCodewords) + count($this->ecCodewords);
    }

    /**
     * Module side length of the symbol (excluding quiet zone).
     *
     * Formulas are the exact ZXing Encoder.java formulas (Apache 2.0, verified against
     * ISO/IEC 24778 Table B.1 anchor: Full Range layer 32 = 151 modules):
     *
     *   Compact:    size = 11 + layers * 4
     *     layer 1 = 15, layer 2 = 19, layer 3 = 23, layer 4 = 27
     *
     *   Full Range: baseSize = 14 + layers * 4
     *               size = baseSize + 1 + 2 * intdiv(intdiv(baseSize, 2) - 1, 15)
     *     The +1 centres the odd-even axis; the 2*(...)/ 15 term adds 2 modules per
     *     side for each reference-grid band (one band every 15 data columns/rows).
     *     layer 1 = 19, layer 8 = 49, layer 9 = 53, layer 16 = 83, layer 32 = 151
     */
    public function size(): int
    {
        if ($this->compact) {
            return 11 + $this->layers * 4;
        }

        $base = 14 + $this->layers * 4;
        return $base + 1 + 2 * intdiv(intdiv($base, 2) - 1, 15);
    }
}
