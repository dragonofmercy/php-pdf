<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * DataMatrix square ECC200 symbol descriptors (ISO/IEC 16022 Table 7).
 *
 * Lists the 24 standard square sizes from 10x10 to 144x144 modules with their
 * data-region layout, codeword counts, and Reed-Solomon block split.
 *
 * @internal
 */
final readonly class Symbol
{

    /**
     * @param int $moduleRows       Total module rows including finder and timing.
     * @param int $moduleCols       Total module cols including finder and timing.
     * @param int $dataRegionRows   Module rows of a single data region (without finder/timing).
     * @param int $dataRegionCols   Module cols of a single data region (without finder/timing).
     * @param int $regionRows       Number of data-region rows in the symbol (1, 2, 4 or 6).
     * @param int $regionCols       Number of data-region cols in the symbol (1, 2, 4 or 6).
     * @param int $dataCodewords    Total data codewords for the symbol.
     * @param int $ecCodewords      Total Reed-Solomon EC codewords for the symbol.
     * @param int $ecBlocks         Number of interleaved RS blocks.
     */
    public function __construct(
        public int $moduleRows,
        public int $moduleCols,
        public int $dataRegionRows,
        public int $dataRegionCols,
        public int $regionRows,
        public int $regionCols,
        public int $dataCodewords,
        public int $ecCodewords,
        public int $ecBlocks,
    ) {}

    public function totalCodewords(): int
    {
        return $this->dataCodewords + $this->ecCodewords;
    }

    /** Number of EC codewords per RS block (constant across the symbol's blocks). */
    public function ecCodewordsPerBlock(): int
    {
        return intdiv($this->ecCodewords, $this->ecBlocks);
    }

    /** @return list<self> */
    public static function all(): array
    {
        // Columns: moduleRows, moduleCols, dataRegionRows, dataRegionCols,
        //          regionRows, regionCols, dataCodewords, ecCodewords, ecBlocks
        $rows = [
            [ 10,  10,  8,  8, 1, 1,    3,    5,  1],
            [ 12,  12, 10, 10, 1, 1,    5,    7,  1],
            [ 14,  14, 12, 12, 1, 1,    8,   10,  1],
            [ 16,  16, 14, 14, 1, 1,   12,   12,  1],
            [ 18,  18, 16, 16, 1, 1,   18,   14,  1],
            [ 20,  20, 18, 18, 1, 1,   22,   18,  1],
            [ 22,  22, 20, 20, 1, 1,   30,   20,  1],
            [ 24,  24, 22, 22, 1, 1,   36,   24,  1],
            [ 26,  26, 24, 24, 1, 1,   44,   28,  1],
            [ 32,  32, 14, 14, 2, 2,   62,   36,  1],
            [ 36,  36, 16, 16, 2, 2,   86,   42,  1],
            [ 40,  40, 18, 18, 2, 2,  114,   48,  1],
            [ 44,  44, 20, 20, 2, 2,  144,   56,  1],
            [ 48,  48, 22, 22, 2, 2,  174,   68,  1],
            [ 52,  52, 24, 24, 2, 2,  204,   84,  2],
            [ 64,  64, 14, 14, 4, 4,  280,  112,  2],
            [ 72,  72, 16, 16, 4, 4,  368,  144,  4],
            [ 80,  80, 18, 18, 4, 4,  456,  192,  4],
            [ 88,  88, 20, 20, 4, 4,  576,  224,  4],
            [ 96,  96, 22, 22, 4, 4,  696,  272,  4],
            [104, 104, 24, 24, 4, 4,  816,  336,  6],
            [120, 120, 18, 18, 6, 6, 1050,  408,  6],
            [132, 132, 20, 20, 6, 6, 1254,  496,  8],
            [144, 144, 22, 22, 6, 6, 1558,  620, 10],
        ];
        $out = [];
        foreach ($rows as $r) {
            $out[] = new self($r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8]);
        }
        return $out;
    }

    public static function pickSmallest(int $dataCodewordCount): self
    {
        foreach (self::all() as $s) {
            if ($s->dataCodewords >= $dataCodewordCount) {
                return $s;
            }
        }
        throw new PdfException(sprintf(
            'DataMatrix data too large: %d codewords exceeds largest square (144x144, 1558 data codewords)',
            $dataCodewordCount,
        ));
    }

    public static function pickByModuleSize(int $size): self
    {
        foreach (self::all() as $s) {
            if ($s->moduleRows === $size && $s->moduleCols === $size) {
                return $s;
            }
        }
        throw new PdfException("No DataMatrix square symbol of size {$size}x{$size}");
    }
}
