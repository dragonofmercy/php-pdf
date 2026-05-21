<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

/**
 * Aztec symbol module matrix.
 *
 * Builds the symbol step by step (call in this order):
 *  1. buildBullseye() - central locator pattern + orientation corner marks.
 *  2. placeModeMessage() - layers + data count + EC, around the bullseye.
 *  3. placeData() - the spiral of data + EC codewords.
 *  4. placeReferenceGrid() (Full Range only) - MUST be last so its writes
 *     are the final word on shared cells, matching zxing-java's encode() flow.
 *
 * The matrix is row-major: `$modules[$row][$col]`. The public mutability
 * lets subsequent build steps (mode message, data spiral) write into the
 * same grid without proliferating accessors. `true` = dark module.
 *
 * Bullseye geometry per ISO/IEC 24778 §6.1 (Compact) / §6.2 (Full Range)
 * and the zxing-java Encoder.drawBullsEye reference (Apache 2.0):
 *   Compact: concentric rings at Chebyshev distance 0, 2, 4 from centre
 *            (9x9 dark/light/dark/light/dark), plus 6 corner orientation
 *            cells at distance 5.
 *   Full:    concentric rings at distance 0, 2, 4, 6 (13x13), plus 6
 *            corner orientation cells at distance 7.
 *
 * @internal
 */
final class Matrix
{
    /** @var array<int, array<int, bool>> */
    public array $modules;

    public function __construct(int $size)
    {
        $this->modules = array_fill(0, $size, array_fill(0, $size, false));
    }

    /**
     * Creates a `$totalSize x $totalSize` matrix and draws the central
     * bullseye plus the six orientation corner cells.
     *
     * `$totalSize` is the full module side length of the symbol (excluding
     * any quiet zone). The bullseye is always centred at `intdiv($totalSize, 2)`.
     *
     * Ported from zxing-java `Encoder.drawBullsEye(BitMatrix, int center, int size)`
     * with `size = 5` for Compact and `size = 7` for Full Range.
     */
    public static function buildBullseye(bool $compact, int $totalSize): self
    {
        $m = new self($totalSize);
        $centre = intdiv($totalSize, 2);
        // zxing's `size` parameter: 5 for Compact (rings at i=0,2,4 -> 9x9),
        // 7 for Full Range (rings at i=0,2,4,6 -> 13x13).
        $size = $compact ? 5 : 7;

        // Concentric dark rings at i = 0, 2, ..., size - 1. Each iteration
        // paints both horizontal edges (top and bottom of the square ring at
        // radius i) and both vertical edges (left and right). i = 0 paints
        // the single centre cell.
        for ($i = 0; $i < $size; $i += 2) {
            for ($j = $centre - $i; $j <= $centre + $i; $j++) {
                $m->modules[$centre - $i][$j] = true;
                $m->modules[$centre + $i][$j] = true;
                $m->modules[$j][$centre - $i] = true;
                $m->modules[$j][$centre + $i] = true;
            }
        }

        // Six orientation corner cells at Chebyshev distance `size` from the
        // centre, one ring outside the bullseye. Coordinates mirror zxing's
        // `matrix.set(col, row)`; here we write `[row][col]`.
        $m->modules[$centre - $size][$centre - $size]     = true; // top-left
        $m->modules[$centre - $size][$centre - $size + 1] = true; // top-left + right
        $m->modules[$centre - $size + 1][$centre - $size] = true; // top-left + below
        $m->modules[$centre - $size][$centre + $size]     = true; // top-right
        $m->modules[$centre - $size + 1][$centre + $size] = true; // top-right + below
        $m->modules[$centre + $size - 1][$centre + $size] = true; // bottom-right + above

        return $m;
    }

    /**
     * Paints the Full Range reference grid bands (ISO/IEC 24778 §6.2).
     *
     * Full Range symbols carry one or more reference grid bands so the
     * scanner can re-sample the module pitch. Bands sit at offsets
     * `j = 0, 16, 32, ...` from the centre, alternating dark/light along
     * each row and column. The `j = 0` band is the central row + column
     * itself; further bands (`j = 16, 32, ...`) appear only when the
     * matrix is large enough (`baseMatrixSize / 2 - 1 > 15`, i.e. layer 5+).
     *
     * A no-op for Compact symbols. For Full Range, the cells overwritten
     * by this pass on the centre row and centre column outside the
     * bullseye are reserved for the reference grid and must NOT have been
     * occupied by data placement; the documented call order is therefore:
     * place data + mode message + bullseye first, then call this method
     * last so its writes are the final word on those cells (matching
     * zxing-java's `Encoder.encode()` flow exactly).
     *
     * Ported from zxing-java `Encoder.encode()` (Apache 2.0), the inner block
     * guarded by `if (compact) ... else { drawBullsEye(...); for (...) {...} }`.
     *
     * @param int $baseMatrixSize Side length excluding grid bands: `(compact ? 11 : 14) + layers * 4`.
     */
    public function placeReferenceGrid(bool $compact, int $baseMatrixSize): void
    {
        if ($compact) {
            return;
        }

        $matrixSize = count($this->modules);
        $centre     = intdiv($matrixSize, 2);
        $parity     = $centre & 1;
        $halfBase   = intdiv($baseMatrixSize, 2) - 1;

        // zxing: for (int i = 0, j = 0; i < baseMatrixSize / 2 - 1; i += 15, j += 16)
        // `i` walks the original (pre-band) coordinate, `j` walks the actual
        // (post-band) matrix coordinate. Each iteration paints one pair of
        // horizontal bands (rows centre +/- j) and one pair of vertical bands
        // (cols centre +/- j) with alternating dark/light cells matching the
        // centre parity.
        for ($i = 0, $j = 0; $i < $halfBase; $i += 15, $j += 16) {
            for ($k = $parity; $k < $matrixSize; $k += 2) {
                $this->modules[$k][$centre - $j] = true;
                $this->modules[$k][$centre + $j] = true;
                $this->modules[$centre - $j][$k] = true;
                $this->modules[$centre + $j][$k] = true;
            }
        }
    }

    /**
     * Places the mode message ring around the bullseye (ISO/IEC 24778 §7.2).
     *
     * The mode message encodes the (layers, dataCodewordCount) pair so the
     * scanner knows the symbol geometry before decoding the data spiral. Bits
     * are packed as:
     *
     *   Compact:    (layers - 1)         : 2 bits
     *               (dataCodewordCount-1): 6 bits   -> 8 info bits
     *               + 5 RS check codewords over GF(16)
     *               = 7 * 4 = 28 bits, placed on the 4 sides of a 1-cell-thick
     *                 ring at Chebyshev distance 5 from the centre.
     *
     *   Full Range: (layers - 1)         : 5 bits
     *               (dataCodewordCount-1): 11 bits  -> 16 info bits
     *               + 6 RS check codewords over GF(16)
     *               = 10 * 4 = 40 bits, placed on the 4 sides of a 2-cell-thick
     *                 ring at distance 7 from the centre. Each side hops over
     *                 the central reference grid cell via offset `i + i / 5`.
     *
     * The bit ordering on each side is taken verbatim from zxing-java
     * `Encoder.drawModeMessage` (Apache 2.0): top side reads bits 0..n-1
     * left-to-right, right side reads the next n bits top-to-bottom, bottom
     * reads the next n bits right-to-left, and left reads the final n bits
     * bottom-to-top.
     */
    public function placeModeMessage(int $layers, int $dataCodewordCount, bool $compact): void
    {
        $bits = self::generateModeMessageBits($layers, $dataCodewordCount, $compact);

        $matrixSize = count($this->modules);
        $centre     = intdiv($matrixSize, 2);

        if ($compact) {
            // 28 bits, 7 per side, ring at distance 5 from centre.
            for ($i = 0; $i < 7; $i++) {
                $offset = $centre - 3 + $i;
                if ($bits[$i]) {
                    $this->modules[$centre - 5][$offset] = true;
                }
                if ($bits[$i + 7]) {
                    $this->modules[$offset][$centre + 5] = true;
                }
                if ($bits[20 - $i]) {
                    $this->modules[$centre + 5][$offset] = true;
                }
                if ($bits[27 - $i]) {
                    $this->modules[$offset][$centre - 5] = true;
                }
            }
        } else {
            // 40 bits, 10 per side, ring at distance 7 from centre. The
            // `intdiv($i, 5)` term shifts positions 5..9 one cell further out
            // so they skip the reference grid column / row at the centre.
            for ($i = 0; $i < 10; $i++) {
                $offset = $centre - 5 + $i + intdiv($i, 5);
                if ($bits[$i]) {
                    $this->modules[$centre - 7][$offset] = true;
                }
                if ($bits[$i + 10]) {
                    $this->modules[$offset][$centre + 7] = true;
                }
                if ($bits[29 - $i]) {
                    $this->modules[$centre + 7][$offset] = true;
                }
                if ($bits[39 - $i]) {
                    $this->modules[$offset][$centre - 7] = true;
                }
            }
        }
    }

    /**
     * Places the data + EC codeword bits in the concentric spiral around the
     * bullseye + mode message (ISO/IEC 24778 §7.3.2 Compact / §7.3.3 Full Range).
     *
     * Data layers are 2 modules thick, walked from innermost (layer 1, the ring
     * immediately outside the mode-message ring) to outermost. Each layer is
     * traversed as four sides (top, right, bottom, left); within a side, bits
     * are placed in pairs with a zigzag pattern. The direction rotates 90
     * degrees clockwise between sides.
     *
     * Bit ordering on the wire:
     *   - The placed bitstream is `startPad` zero bits + every codeword unpacked
     *     MSB-first, where `startPad = totalBitsInLayer % codewordBits` and
     *     `totalBitsInLayer = ((compact ? 88 : 112) + 16 * layers) * layers`.
     *   - The number of codewords MUST equal `(totalBitsInLayer - startPad) / codewordBits`.
     *
     * For Full Range, the spiral skips the reference grid rows/columns via an
     * `alignmentMap` that translates between symbol coordinates and matrix
     * coordinates. Compact uses an identity map.
     *
     * Ported from zxing-java `Encoder.encode` (Apache 2.0), specifically the
     * `for (int i = 0, rowOffset = 0; i < layers; i++)` block.
     *
     * @param list<int> $codewords    Data codewords followed by EC codewords, in placement order.
     * @param int       $codewordBits Bits per codeword (6, 8, 10, or 12).
     * @param int       $layers       Data layer count (1..4 for Compact, 1..32 for Full Range).
     * @param bool      $compact      True for Compact symbols, false for Full Range.
     */
    public function placeData(array $codewords, int $codewordBits, int $layers, bool $compact): void
    {
        $bits = self::expandCodewordsToBits($codewords, $codewordBits, $layers, $compact);

        $baseMatrixSize = ($compact ? 11 : 14) + $layers * 4;
        $alignmentMap   = self::buildAlignmentMap($compact, $baseMatrixSize);

        // Walk the data layers from innermost (i = 0) to outermost (i = layers - 1).
        // Each layer is 2 modules thick: positions i*2+k and baseMatrixSize-1-i*2-k for k in {0,1}.
        $rowOffset = 0;
        for ($i = 0; $i < $layers; $i++) {
            // Side length of layer i, in "pair" units. Compact: 4*(layers - i) + 9;
            // Full Range: 4*(layers - i) + 12. Each side carries `rowSize * 2` bits.
            $rowSize = ($layers - $i) * 4 + ($compact ? 9 : 12);

            for ($j = 0; $j < $rowSize; $j++) {
                $columnOffset = $j * 2;
                for ($k = 0; $k < 2; $k++) {
                    // Top side: column varies (j), row fixed near top (i*2+k).
                    if ($bits[$rowOffset + $columnOffset + $k]) {
                        $col = $alignmentMap[$i * 2 + $k];
                        $row = $alignmentMap[$i * 2 + $j];
                        $this->modules[$row][$col] = true;
                    }
                    // Right side: column fixed near right, row varies (j).
                    if ($bits[$rowOffset + $rowSize * 2 + $columnOffset + $k]) {
                        $col = $alignmentMap[$i * 2 + $j];
                        $row = $alignmentMap[$baseMatrixSize - 1 - $i * 2 - $k];
                        $this->modules[$row][$col] = true;
                    }
                    // Bottom side: column varies (mirrored), row fixed near bottom.
                    if ($bits[$rowOffset + $rowSize * 4 + $columnOffset + $k]) {
                        $col = $alignmentMap[$baseMatrixSize - 1 - $i * 2 - $k];
                        $row = $alignmentMap[$baseMatrixSize - 1 - $i * 2 - $j];
                        $this->modules[$row][$col] = true;
                    }
                    // Left side: column fixed near left, row varies (mirrored).
                    if ($bits[$rowOffset + $rowSize * 6 + $columnOffset + $k]) {
                        $col = $alignmentMap[$baseMatrixSize - 1 - $i * 2 - $j];
                        $row = $alignmentMap[$i * 2 + $k];
                        $this->modules[$row][$col] = true;
                    }
                }
            }
            // Each layer consumed 4 sides * rowSize pairs * 2 bits = rowSize * 8 bits.
            $rowOffset += $rowSize * 8;
        }
    }

    /**
     * Expand codewords into the full bitstream that gets placed by the spiral.
     *
     * Mirrors zxing's `generateCheckWords` tail: prepend
     * `totalBitsInLayer % codewordBits` zero bits, then unpack each codeword
     * MSB-first in placement order. The resulting length equals
     * `totalBitsInLayer`.
     *
     * @param list<int> $codewords
     * @return list<bool>
     */
    private static function expandCodewordsToBits(array $codewords, int $codewordBits, int $layers, bool $compact): array
    {
        $totalBitsInLayer = (($compact ? 88 : 112) + 16 * $layers) * $layers;
        $startPad         = $totalBitsInLayer % $codewordBits;

        $bits = array_fill(0, $startPad, false);
        foreach ($codewords as $word) {
            for ($j = $codewordBits - 1; $j >= 0; $j--) {
                $bits[] = (($word >> $j) & 1) === 1;
            }
        }
        return $bits;
    }

    /**
     * Build the alignmentMap that translates spiral symbol coordinates to
     * actual matrix row/column indices.
     *
     * Compact: identity mapping. Full Range: skips the reference-grid rows
     * and columns at the centre and at every 16-cell band beyond it.
     *
     * Ported from zxing-java `Encoder.encode` (Apache 2.0).
     *
     * @return list<int>
     */
    private static function buildAlignmentMap(bool $compact, int $baseMatrixSize): array
    {
        if ($compact) {
            $map = [];
            for ($i = 0; $i < $baseMatrixSize; $i++) {
                $map[] = $i;
            }
            return $map;
        }

        $matrixSize = $baseMatrixSize + 1 + 2 * intdiv(intdiv($baseMatrixSize, 2) - 1, 15);
        $origCentre = intdiv($baseMatrixSize, 2);
        $centre     = intdiv($matrixSize, 2);

        // Compute the two halves separately so we can assemble a strict list.
        // Lower half (indices 0..origCentre-1): position origCentre-i-1 -> centre-newOffset-1
        // Upper half (indices origCentre..baseMatrixSize-1): position origCentre+i -> centre+newOffset+1
        $lower = [];
        $upper = [];
        for ($i = $origCentre - 1; $i >= 0; $i--) {
            $newOffset = $i + intdiv($i, 15);
            $lower[]   = $centre - $newOffset - 1;
        }
        for ($i = 0; $i < $origCentre; $i++) {
            $newOffset = $i + intdiv($i, 15);
            $upper[]   = $centre + $newOffset + 1;
        }
        return [...$lower, ...$upper];
    }

    /**
     * Compute the mode-message bitstream: info bits + RS check codewords,
     * unpacked to a flat list of booleans (MSB-first within each codeword).
     *
     * Mirrors zxing-java `Encoder.generateModeMessage` + `generateCheckWords`
     * with `wordSize = 4` and `totalBits = 28` (Compact) or 40 (Full Range).
     *
     * @return list<bool>
     */
    private static function generateModeMessageBits(int $layers, int $dataCodewordCount, bool $compact): array
    {
        if ($compact) {
            // 8 info bits: (layers-1):2 + (dataCodewords-1):6.
            $info = (($layers - 1) << 6) | ($dataCodewordCount - 1);
            $data = [
                ($info >> 4) & 0xF,
                $info & 0xF,
            ];
            $ecCount = 5;
        } else {
            // 16 info bits: (layers-1):5 + (dataCodewords-1):11.
            $info = (($layers - 1) << 11) | ($dataCodewordCount - 1);
            $data = [
                ($info >> 12) & 0xF,
                ($info >> 8) & 0xF,
                ($info >> 4) & 0xF,
                $info & 0xF,
            ];
            $ecCount = 6;
        }

        $ec    = ReedSolomon::compute($data, $ecCount, GaloisField::gf16());
        $words = [...$data, ...$ec];

        // Unpack each 4-bit codeword high-bit-first. zxing's BitArray reads
        // the same way: `appendBits(value, 4)` writes bit 3, bit 2, bit 1, bit 0.
        $bits = [];
        foreach ($words as $word) {
            for ($j = 3; $j >= 0; $j--) {
                $bits[] = (($word >> $j) & 1) === 1;
            }
        }
        return $bits;
    }
}
