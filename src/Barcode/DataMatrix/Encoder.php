<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * DataMatrix ECC200 encoder orchestrator.
 *
 * Pipeline:
 *   input string
 *     -> HighLevelEncoder (Annex P shortest-path codewords)
 *     -> Symbol::pickSmallest (auto-fit symbol)
 *     -> pad (codeword 129 + 253-state randomized pads)
 *     -> split into RS blocks, compute RS EC per block, interleave
 *     -> EncodeResult
 *
 * @internal
 */
final class Encoder
{
    private const int PAD_CODEWORD = 129;

    public static function encode(string $input): EncodeResult
    {
        if ($input === '') {
            throw new PdfException('DataMatrix data must not be empty');
        }
        $rawCodewords = HighLevelEncoder::encode($input);
        $symbol       = Symbol::pickSmallest(count($rawCodewords));
        $paddedData   = self::pad($rawCodewords, $symbol->dataCodewords);
        $finalStream  = self::interleave($paddedData, $symbol);
        return new EncodeResult($symbol, $finalStream);
    }

    /**
     * Pad the data codeword stream to the symbol's data capacity.
     *
     * First pad is codeword 129. Subsequent pads use the 253-state algorithm
     * (ISO 16022 5.2.3):
     *   r = ((149 * position) % 253) + 1   (position is 1-based)
     *   pad_n = (129 + r) mod 254
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function pad(array $data, int $capacity): array
    {
        if (count($data) === $capacity) {
            return $data;
        }
        $out = $data;
        $out[] = self::PAD_CODEWORD;
        while (count($out) < $capacity) {
            $position = count($out) + 1;
            $r = ((149 * $position) % 253) + 1;
            $out[] = (self::PAD_CODEWORD + $r) % 254;
        }
        return $out;
    }

    /**
     * Split the data into ecBlocks blocks, compute RS EC per block, and
     * interleave per ISO/IEC 16022 5.8.4.
     *
     * Data distribution is round-robin (block 0 takes indices 0, blockCount,
     * 2*blockCount, ...). EC interleaving emits all blocks' i-th EC codeword
     * before moving to i+1.
     *
     * @param list<int> $paddedData
     * @return list<int>
     */
    private static function interleave(array $paddedData, Symbol $symbol): array
    {
        $blockCount = $symbol->ecBlocks;
        $ecPerBlock = $symbol->ecCodewordsPerBlock();
        $totalData  = $symbol->dataCodewords;
        $blocks = array_fill(0, $blockCount, []);
        for ($i = 0; $i < $totalData; $i++) {
            $blocks[$i % $blockCount][] = $paddedData[$i];
        }
        $ecBlocks = [];
        foreach ($blocks as $b) {
            /** @var list<int> $b */
            $ecBlocks[] = ReedSolomon::compute($b, $ecPerBlock);
        }
        $out = $paddedData;
        for ($i = 0; $i < $ecPerBlock; $i++) {
            for ($b = 0; $b < $blockCount; $b++) {
                $out[] = $ecBlocks[$b][$i];
            }
        }
        return $out;
    }
}
