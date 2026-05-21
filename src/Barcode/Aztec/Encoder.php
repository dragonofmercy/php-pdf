<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\AztecEc;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Aztec encoder orchestrator: picks the smallest symbol variant + layer count
 * that can hold the input data at the requested error correction level.
 *
 * Algorithm and capacity formulas ported from zxing-java Encoder.java (Apache 2.0):
 *   com.google.zxing.aztec.encoder.Encoder.encode
 *
 * The encoder iterates candidate symbols in this order:
 *
 *   i = 0..3 -> Compact, layers = i + 1   (Compact 1, 2, 3, 4)
 *   i = 4..32 -> Full Range, layers = i   (Full 4..32)
 *
 * Full 1..3 are intentionally skipped: Compact 2..4 occupy the same module
 * footprint with strictly more data capacity, so they are always preferred.
 *
 * For each candidate, capacity is computed as:
 *
 *   totalBitsInLayer = ((compact ? 88 : 112) + 16 * layers) * layers
 *   wordSize         = WORD_SIZE[i]   (6/8/10/12, ISO/IEC 24778 Table 4)
 *   usableBits       = totalBitsInLayer - (totalBitsInLayer % wordSize)
 *   totalCodewords   = usableBits / wordSize
 *
 * The fit check follows zxing exactly:
 *
 *   eccBits = bitsSize * minECCPercent / 100 + 11   (integer division)
 *   stuffedBits = LowLevelEncoder::stuffBits(bits, wordSize)
 *   fits if (stuffedBits + eccBits) <= usableBits
 *
 * Compact 4 additionally caps data at 64 codewords (the mode message size
 * field is only 6 bits, so messageSize - 1 must fit in 6 bits).
 *
 * EC takes the remaining budget after the data: the chosen symbol is filled
 * to capacity rather than truncated to the minimum required EC. This matches
 * zxing's behaviour and is what readers expect.
 *
 * @internal
 */
final class Encoder
{
    /**
     * Codeword bits indexed by layer count (1..32). Index 0 holds the mode
     * message word size (4 bits) and is not used by encode().
     *
     * Layers  1..2 -> 6-bit codewords
     * Layers  3..8 -> 8-bit codewords
     * Layers 9..22 -> 10-bit codewords
     * Layers 23..32 -> 12-bit codewords
     *
     * Verbatim copy of zxing's WORD_SIZE table (Apache 2.0).
     *
     * @var list<int>
     */
    private const array WORD_SIZE = [
        4, 6, 6, 8, 8, 8, 8, 8, 8, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10,
        12, 12, 12, 12, 12, 12, 12, 12, 12, 12,
    ];

    private const int MAX_NB_BITS = 32;

    private function __construct() {}

    /**
     * Encode the given user data into an Aztec symbol descriptor.
     *
     * @param string  $data Raw user data (any byte sequence; UTF-8 is auto-detected
     *                      by HighLevelEncoder and prefixed with an ECI escape).
     * @param AztecEc $ec   Error correction level preset.
     *
     * @return EncodeResult The selected symbol variant with stuffed data and EC codewords.
     *
     * @throws PdfException If the data exceeds the largest Aztec symbol
     *                      (Full Range layer 32) or any nested encoder step fails.
     */
    public static function encode(string $data, AztecEc $ec): EncodeResult
    {
        $bits = HighLevelEncoder::encode($data);
        $bitsSize = strlen($bits);

        // EC budget in bits, zxing convention: percent * bitsSize / 100 + 11.
        $eccBits = intdiv($bitsSize * $ec->redundancyPercent(), 100) + 11;
        $totalSizeBits = $bitsSize + $eccBits;

        /** @var list<int>|null $stuffedBits */
        $stuffedBits = null;
        $stuffedWordSize = 0;

        // Iterate Compact 1..4, then Full 4..32.
        for ($i = 0; ; $i++) {
            if ($i > self::MAX_NB_BITS) {
                throw new PdfException(
                    'Aztec data too large: encoded payload exceeds Full Range capacity (32 layers)',
                );
            }

            $compact = $i <= 3;
            $layers = $compact ? $i + 1 : $i;
            $totalBitsInLayer = self::totalBitsInLayer($layers, $compact);

            // Cheap precheck before bit-stuffing.
            if ($totalSizeBits > $totalBitsInLayer) {
                continue;
            }

            $wordSize = self::WORD_SIZE[$layers];

            // Re-stuff only when the word size actually changes (matches zxing).
            if ($stuffedBits === null || $stuffedWordSize !== $wordSize) {
                $stuffedBits = LowLevelEncoder::stuffBits($bits, $wordSize);
                $stuffedWordSize = $wordSize;
            }

            $stuffedBitCount = count($stuffedBits) * $wordSize;
            $usableBits = $totalBitsInLayer - ($totalBitsInLayer % $wordSize);

            // Compact 4 (and 3) can only carry 64 data words because the
            // mode message size field is 6 bits wide. Skip if exceeded.
            if ($compact && count($stuffedBits) > 64) {
                continue;
            }

            if ($stuffedBitCount + $eccBits <= $usableBits) {
                // Use the entire remaining budget for EC (zxing convention):
                // ecCount = totalCodewords - dataCodewords.
                $ecCount = intdiv($usableBits, $wordSize) - count($stuffedBits);
                $ecCodewords = ReedSolomon::compute(
                    $stuffedBits,
                    $ecCount,
                    GaloisField::forCodewordBits($wordSize),
                );

                return new EncodeResult(
                    compact: $compact,
                    layers: $layers,
                    codewordBits: $wordSize,
                    dataCodewords: $stuffedBits,
                    ecCodewords: $ecCodewords,
                );
            }
        }
    }

    /**
     * Total bits available in the symbol layers (excluding finder, mode message,
     * and reference grid). Exact zxing formula:
     *
     *   ((compact ? 88 : 112) + 16 * layers) * layers
     *
     * Compact 1: 104, Compact 2: 240, Compact 3: 408, Compact 4: 608.
     * Full 1: 128, Full 4: 704, Full 8: 1920, Full 32: 19968.
     */
    private static function totalBitsInLayer(int $layers, bool $compact): int
    {
        return (($compact ? 88 : 112) + 16 * $layers) * $layers;
    }
}
