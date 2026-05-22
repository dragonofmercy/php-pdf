<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * PDF417 encoder orchestrator (ISO/IEC 15438 8.5-8.6).
 *
 * Pipeline: HighLevelEncoder -> choose geometry/EC -> assemble the data region
 * (length descriptor + data + pad 900) -> Reed-Solomon -> row-major grid.
 *
 * @internal
 */
final class Encoder
{
    private const int PAD_CODEWORD = 900;

    public static function encode(string $input, ?int $ecLevel, ?int $columnHint): EncodeResult
    {
        if ($input === '') {
            throw new PdfException('PDF417 data must not be empty');
        }

        $data = HighLevelEncoder::encode($input);
        // +1 for the length descriptor that will head the data region.
        $dataCount = count($data) + 1;

        $level = $ecLevel ?? Symbol::recommendedEcLevel($dataCount);
        $symbol = Symbol::choose($dataCount, $level, $columnHint);

        $dataRegion = $symbol->rows * $symbol->columns - $symbol->ecCodewords;

        if ($dataCount > $dataRegion) {
            throw new PdfException(sprintf(
                'PDF417 data too large: %d data codewords exceed the %d-codeword data region',
                $dataCount, $dataRegion,
            ));
        }

        // Data region = [length descriptor] + data + pad(900) to fill dataRegion.
        $region = $data;
        while (count($region) + 1 < $dataRegion) {
            $region[] = self::PAD_CODEWORD;
        }
        array_unshift($region, $dataRegion); // descriptor = full data-region length

        $ec = ReedSolomon::compute($region, $symbol->ecCodewords);

        /** @var list<int> $grid */
        $grid = array_merge($region, $ec);

        return new EncodeResult($symbol->columns, $symbol->rows, $symbol->ecLevel, $grid);
    }
}
