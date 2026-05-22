<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * PDF417 symbol geometry: column/row counts and EC-level selection
 * (ISO/IEC 15438 8.5 + Annex E recommended EC levels).
 *
 * @internal
 */
final readonly class Symbol
{
    public function __construct(
        public int $columns,
        public int $rows,
        public int $ecLevel,
        public int $ecCodewords,
    ) {}

    /** EC codeword count for a level: 2^(level+1). */
    public static function ecCodewordCount(int $level): int
    {
        return 1 << ($level + 1);
    }

    /** ISO/IEC 15438 Annex E recommended EC level for a data codeword count. */
    public static function recommendedEcLevel(int $dataCodewords): int
    {
        return match (true) {
            $dataCodewords <= 40  => 2,
            $dataCodewords <= 160 => 3,
            $dataCodewords <= 320 => 4,
            $dataCodewords <= 863 => 5,
            default               => 6,
        };
    }

    /**
     * Choose columns + rows for the given data count (which already INCLUDES the
     * symbol-length descriptor codeword), EC level, and optional column hint.
     * Total codewords (data + EC) must fit a 1-30 column x 3-90 row grid.
     */
    public static function choose(int $dataCodewords, int $ecLevel, ?int $columnHint): self
    {
        $ec = self::ecCodewordCount($ecLevel);
        $total = $dataCodewords + $ec;

        $columns = $columnHint ?? max(1, min(30, (int) round(sqrt($total / 3.0))));
        $rows = max(3, (int) ceil($total / $columns));

        if ($columns < 1 || $columns > 30 || $rows > 90) {
            throw new PdfException(sprintf(
                'PDF417 data too large: %d codewords exceeds capacity at %d columns / EC level %d',
                $total, $columns, $ecLevel,
            ));
        }

        return new self($columns, $rows, $ecLevel, $ec);
    }
}
