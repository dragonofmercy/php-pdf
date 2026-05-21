<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

/**
 * DataMatrix module grid: finder L + timing patterns + codeword placement
 * via the standard "Utah" algorithm (ISO/IEC 16022 5.8.1).
 *
 * Modules: `[$row][$col]`, top-left origin. true = dark, false = light.
 *
 * Multi-region symbols (>= 32x32) carry per-region L finders and timing
 * tracks. The data placement walker operates on a virtual data grid of
 * dimensions (dataRegionRows * regionRows) x (dataRegionCols * regionCols);
 * the grid-to-module translation skips finder rows/cols at region boundaries.
 *
 * @internal
 */
final class Matrix
{
    /** @var array<int, array<int, bool>> */
    public array $modules;

    /** @var array<int, array<int, bool>> True = reserved by finder/timing/region border. */
    private array $reserved;

    public readonly int $dataGridRows;
    public readonly int $dataGridCols;

    private function __construct(public readonly Symbol $symbol)
    {
        $this->dataGridRows = $symbol->dataRegionRows * $symbol->regionRows;
        $this->dataGridCols = $symbol->dataRegionCols * $symbol->regionCols;
        $rows = $symbol->moduleRows;
        $cols = $symbol->moduleCols;
        $this->modules  = array_fill(0, $rows, array_fill(0, $cols, false));
        $this->reserved = array_fill(0, $rows, array_fill(0, $cols, false));
    }

    public static function build(Symbol $symbol): self
    {
        $m = new self($symbol);
        $m->paintFinders();
        return $m;
    }

    /**
     * Paint the L finder + timing for every data region. Each region of size
     * (dataRegionRows x dataRegionCols) is bordered by:
     *   - left col: solid dark (L vertical leg)
     *   - bottom row: solid dark (L horizontal base)
     *   - top row: alternating dark/light starting dark (timing)
     *   - right col: alternating dark/light starting dark (timing)
     */
    private function paintFinders(): void
    {
        $rRows = $this->symbol->regionRows;
        $rCols = $this->symbol->regionCols;
        $drR   = $this->symbol->dataRegionRows;
        $drC   = $this->symbol->dataRegionCols;
        $regionStrideR = $drR + 2;
        $regionStrideC = $drC + 2;
        for ($ry = 0; $ry < $rRows; $ry++) {
            for ($rx = 0; $rx < $rCols; $rx++) {
                $y0 = $ry * $regionStrideR;
                $x0 = $rx * $regionStrideC;
                $rightX = $x0 + $regionStrideC - 1;
                for ($y = $y0; $y < $y0 + $regionStrideR; $y++) {
                    $isDark = (($y - $y0) % 2) === 0;
                    $this->modules[$y][$rightX] = $isDark;
                    $this->reserved[$y][$rightX] = true;
                }
                $topY = $y0;
                for ($x = $x0; $x < $x0 + $regionStrideC; $x++) {
                    $isDark = (($x - $x0) % 2) === 0;
                    $this->modules[$topY][$x] = $isDark;
                    $this->reserved[$topY][$x] = true;
                }
                $bottomY = $y0 + $regionStrideR - 1;
                for ($x = $x0; $x < $x0 + $regionStrideC; $x++) {
                    $this->modules[$bottomY][$x] = true;
                    $this->reserved[$bottomY][$x] = true;
                }
                for ($y = $y0; $y < $y0 + $regionStrideR; $y++) {
                    $this->modules[$y][$x0] = true;
                    $this->reserved[$y][$x0] = true;
                }
            }
        }
    }

    /**
     * Place the interleaved data + EC codewords using the ISO/IEC 16022 Utah
     * algorithm (5.8.1). Walks the virtual data grid (excluding finder/timing)
     * and places 8 bits per codeword in the standard L-tetromino pattern, with
     * 4 corner-case exceptions for the symbol's first/last bits at the data
     * region corners.
     *
     * @param list<int> $codewords Final padded + interleaved stream from Encoder.
     */
    public function placeCodewords(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $this->walkData($bits);
    }

    /**
     * Run the Utah-pattern data-grid walker (ISO 16022 5.8.1).
     * Extracted so the walker's mutating state is analysed in isolation,
     * keeping each path through the corner-case cascade reachable.
     */
    private function walkData(string $bits): void
    {
        $rows = $this->dataGridRows;
        $cols = $this->dataGridCols;
        $row = 4;
        $col = 0;
        $bitIdx = 0;

        do {
            $bitIdx = $this->maybePlaceCorner($row, $col, $bits, $bitIdx, $rows, $cols);
            [$row, $col, $bitIdx] = $this->walkUpRight($row, $col, $bits, $bitIdx, $rows, $cols);
            $row += 1;
            $col += 3;
            [$row, $col, $bitIdx] = $this->walkDownLeft($row, $col, $bits, $bitIdx, $rows, $cols);
            $row += 3;
            $col += 1;
        } while ($row < $rows || $col < $cols);
    }

    /**
     * @return array{int, int, int} New ($row, $col, $bitIdx) after the up-right walk.
     */
    private function walkUpRight(int $row, int $col, string $bits, int $bitIdx, int $rows, int $cols): array
    {
        do {
            if ($row < $rows && $col >= 0 && !$this->isReservedDataCell($row, $col)) {
                $bitIdx += $this->placeUtah($row, $col, $bits, $bitIdx, $rows, $cols);
            }
            $row -= 2;
            $col += 2;
        } while ($row >= 0 && $col < $cols);
        return [$row, $col, $bitIdx];
    }

    /**
     * @return array{int, int, int} New ($row, $col, $bitIdx) after the down-left walk.
     */
    private function walkDownLeft(int $row, int $col, string $bits, int $bitIdx, int $rows, int $cols): array
    {
        do {
            if ($row >= 0 && $col < $cols && !$this->isReservedDataCell($row, $col)) {
                $bitIdx += $this->placeUtah($row, $col, $bits, $bitIdx, $rows, $cols);
            }
            $row += 2;
            $col -= 2;
        } while ($row < $rows && $col >= 0);
        return [$row, $col, $bitIdx];
    }

    /**
     * Trigger one of the four ISO 16022 5.8.1 corner-case bit placements
     * if the walker has arrived at the matching ($row, $col) corner state.
     */
    private function maybePlaceCorner(int $row, int $col, string $bits, int $bitIdx, int $rows, int $cols): int
    {
        if ($row === $rows && $col === 0) {
            return $bitIdx + $this->placeCorner1($bits, $bitIdx, $rows, $cols);
        }
        if ($row === $rows - 2 && $col === 0 && ($cols % 4) !== 0) {
            return $bitIdx + $this->placeCorner2($bits, $bitIdx, $rows, $cols);
        }
        if ($row === $rows - 2 && $col === 0 && ($cols % 8) === 4) {
            return $bitIdx + $this->placeCorner3($bits, $bitIdx, $rows, $cols);
        }
        if ($row === $rows + 4 && $col === 2 && ($cols % 8) === 0) {
            return $bitIdx + $this->placeCorner4($bits, $bitIdx, $rows, $cols);
        }
        return $bitIdx;
    }

    /**
     * Place one Utah pattern (8 bits) at module-grid offsets relative to ($row, $col)
     * per ISO 5.8.1 Figure 16. Returns 8 (bits consumed).
     */
    private function placeUtah(int $row, int $col, string $bits, int $bitIdx, int $rows, int $cols): int
    {
        $this->writeBit($row - 2, $col - 2, $bits, $bitIdx + 0, $rows, $cols);
        $this->writeBit($row - 2, $col - 1, $bits, $bitIdx + 1, $rows, $cols);
        $this->writeBit($row - 1, $col - 2, $bits, $bitIdx + 2, $rows, $cols);
        $this->writeBit($row - 1, $col - 1, $bits, $bitIdx + 3, $rows, $cols);
        $this->writeBit($row - 1, $col,     $bits, $bitIdx + 4, $rows, $cols);
        $this->writeBit($row,     $col - 2, $bits, $bitIdx + 5, $rows, $cols);
        $this->writeBit($row,     $col - 1, $bits, $bitIdx + 6, $rows, $cols);
        $this->writeBit($row,     $col,     $bits, $bitIdx + 7, $rows, $cols);
        return 8;
    }

    private function placeCorner1(string $bits, int $bitIdx, int $rows, int $cols): int
    {
        $this->writeBit($rows - 1, 0,        $bits, $bitIdx + 0, $rows, $cols);
        $this->writeBit($rows - 1, 1,        $bits, $bitIdx + 1, $rows, $cols);
        $this->writeBit($rows - 1, 2,        $bits, $bitIdx + 2, $rows, $cols);
        $this->writeBit(0,        $cols - 2, $bits, $bitIdx + 3, $rows, $cols);
        $this->writeBit(0,        $cols - 1, $bits, $bitIdx + 4, $rows, $cols);
        $this->writeBit(1,        $cols - 1, $bits, $bitIdx + 5, $rows, $cols);
        $this->writeBit(2,        $cols - 1, $bits, $bitIdx + 6, $rows, $cols);
        $this->writeBit(3,        $cols - 1, $bits, $bitIdx + 7, $rows, $cols);
        return 8;
    }

    private function placeCorner2(string $bits, int $bitIdx, int $rows, int $cols): int
    {
        $this->writeBit($rows - 3, 0,        $bits, $bitIdx + 0, $rows, $cols);
        $this->writeBit($rows - 2, 0,        $bits, $bitIdx + 1, $rows, $cols);
        $this->writeBit($rows - 1, 0,        $bits, $bitIdx + 2, $rows, $cols);
        $this->writeBit(0,        $cols - 4, $bits, $bitIdx + 3, $rows, $cols);
        $this->writeBit(0,        $cols - 3, $bits, $bitIdx + 4, $rows, $cols);
        $this->writeBit(0,        $cols - 2, $bits, $bitIdx + 5, $rows, $cols);
        $this->writeBit(0,        $cols - 1, $bits, $bitIdx + 6, $rows, $cols);
        $this->writeBit(1,        $cols - 1, $bits, $bitIdx + 7, $rows, $cols);
        return 8;
    }

    private function placeCorner3(string $bits, int $bitIdx, int $rows, int $cols): int
    {
        $this->writeBit($rows - 3, 0,        $bits, $bitIdx + 0, $rows, $cols);
        $this->writeBit($rows - 2, 0,        $bits, $bitIdx + 1, $rows, $cols);
        $this->writeBit($rows - 1, 0,        $bits, $bitIdx + 2, $rows, $cols);
        $this->writeBit(0,        $cols - 2, $bits, $bitIdx + 3, $rows, $cols);
        $this->writeBit(0,        $cols - 1, $bits, $bitIdx + 4, $rows, $cols);
        $this->writeBit(1,        $cols - 1, $bits, $bitIdx + 5, $rows, $cols);
        $this->writeBit(2,        $cols - 1, $bits, $bitIdx + 6, $rows, $cols);
        $this->writeBit(3,        $cols - 1, $bits, $bitIdx + 7, $rows, $cols);
        return 8;
    }

    private function placeCorner4(string $bits, int $bitIdx, int $rows, int $cols): int
    {
        $this->writeBit($rows - 1, 0,         $bits, $bitIdx + 0, $rows, $cols);
        $this->writeBit($rows - 1, $cols - 1, $bits, $bitIdx + 1, $rows, $cols);
        $this->writeBit(0,         $cols - 3, $bits, $bitIdx + 2, $rows, $cols);
        $this->writeBit(0,         $cols - 2, $bits, $bitIdx + 3, $rows, $cols);
        $this->writeBit(0,         $cols - 1, $bits, $bitIdx + 4, $rows, $cols);
        $this->writeBit(1,         $cols - 3, $bits, $bitIdx + 5, $rows, $cols);
        $this->writeBit(1,         $cols - 2, $bits, $bitIdx + 6, $rows, $cols);
        $this->writeBit(1,         $cols - 1, $bits, $bitIdx + 7, $rows, $cols);
        return 8;
    }

    /**
     * Write one bit from $bits[$bitIdx] into the matrix at data-grid coords
     * ($dgRow, $dgCol), wrapping per ISO 5.8.1 (negative coords wrap).
     */
    private function writeBit(int $dgRow, int $dgCol, string $bits, int $bitIdx, int $rows, int $cols): void
    {
        if ($dgRow < 0) {
            // ISO/IEC 16022 5.8.1 wrap rule for data grids that are not a multiple
            // of 8 in either dimension: wrap negative coordinates and shift the
            // orthogonal axis by 4 - ((dim + 4) % 8) to land on a valid cell.
            $dgRow += $rows;
            $dgCol += 4 - (($rows + 4) % 8);
        }
        if ($dgCol < 0) {
            // Same wrap rule, transposed.
            $dgCol += $cols;
            $dgRow += 4 - (($cols + 4) % 8);
        }
        if ($bitIdx >= strlen($bits)) {
            return;
        }
        [$mRow, $mCol] = $this->dataGridToModule($dgRow, $dgCol);
        $this->modules[$mRow][$mCol] = ($bits[$bitIdx] === '1');
    }

    /** @return array{int, int} */
    private function dataGridToModule(int $dgRow, int $dgCol): array
    {
        $drR = $this->symbol->dataRegionRows;
        $drC = $this->symbol->dataRegionCols;
        $ry = intdiv($dgRow, $drR);
        $rx = intdiv($dgCol, $drC);
        $mRow = $ry * ($drR + 2) + 1 + ($dgRow % $drR);
        $mCol = $rx * ($drC + 2) + 1 + ($dgCol % $drC);
        return [$mRow, $mCol];
    }

    private function isReservedDataCell(int $dgRow, int $dgCol): bool
    {
        if ($dgRow < 0 || $dgCol < 0
            || $dgRow >= $this->dataGridRows
            || $dgCol >= $this->dataGridCols
        ) {
            return true;
        }
        [$mRow, $mCol] = $this->dataGridToModule($dgRow, $dgCol);
        return $this->reserved[$mRow][$mCol];
    }
}
