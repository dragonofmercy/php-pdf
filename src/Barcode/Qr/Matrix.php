<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

/**
 * QR Code matrix container + builder. The matrix is a square grid of
 * boolean modules (true = dark). `reserved[y][x]` flags whether a position
 * is a function pattern (must not be overwritten by data or masking).
 *
 * Supports versions 1-40 (full ISO 18004 range).
 *
 * @internal
 */
final class Matrix
{
    /**
     * Alignment pattern centre coordinates per ISO 18004 Annex E (Table E.1).
     * Indexed by version 1..40. Cartesian product of the listed positions
     * gives the centres; positions overlapping a finder are skipped.
     *
     * @var array<int, list<int>>
     */
    private const array ALIGNMENT_POSITIONS = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
        21 => [6, 28, 50, 72, 94],
        22 => [6, 26, 50, 74, 98],
        23 => [6, 30, 54, 78, 102],
        24 => [6, 28, 54, 80, 106],
        25 => [6, 32, 58, 84, 110],
        26 => [6, 30, 58, 86, 114],
        27 => [6, 34, 62, 90, 118],
        28 => [6, 26, 50, 74, 98, 122],
        29 => [6, 30, 54, 78, 102, 126],
        30 => [6, 26, 52, 78, 104, 130],
        31 => [6, 30, 56, 82, 108, 134],
        32 => [6, 34, 60, 86, 112, 138],
        33 => [6, 30, 58, 86, 114, 142],
        34 => [6, 34, 62, 90, 118, 146],
        35 => [6, 30, 54, 78, 102, 126, 150],
        36 => [6, 24, 50, 76, 102, 128, 154],
        37 => [6, 28, 54, 80, 106, 132, 158],
        38 => [6, 32, 58, 84, 110, 136, 162],
        39 => [6, 26, 54, 82, 110, 138, 166],
        40 => [6, 30, 58, 86, 114, 142, 170],
    ];

    /** @var array<int, array<int, bool>> */
    public array $modules;

    /** @var array<int, array<int, bool>> */
    public array $reserved;

    public int $size;

    private function __construct(public int $version)
    {
        $this->size = 17 + 4 * $version;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->reserved = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    /**
     * Builds a matrix with all function patterns placed but no data and no masking.
     */
    public static function buildEmpty(int $version): self
    {
        $m = new self($version);
        $m->placeFinder(0, 0);
        $m->placeFinder($m->size - 7, 0);
        $m->placeFinder(0, $m->size - 7);
        $m->placeTiming();
        $m->placeDarkModule();
        $m->placeAlignmentPatterns();
        $m->reserveFormatInfo();
        if ($version >= 7) {
            $m->reserveVersionInfo();
        }
        return $m;
    }

    private function placeFinder(int $col, int $row): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $row + $r;
                $cc = $col + $c;
                if ($rr < 0 || $rr >= $this->size || $cc < 0 || $cc >= $this->size) {
                    continue;
                }
                $isFinder = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6);
                $isDark = false;
                if ($isFinder) {
                    if ($r === 0 || $r === 6 || $c === 0 || $c === 6) {
                        $isDark = true;
                    } elseif ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4) {
                        $isDark = true;
                    }
                }
                $this->modules[$rr][$cc] = $isDark;
                $this->reserved[$rr][$cc] = true;
            }
        }
    }

    private function placeTiming(): void
    {
        for ($i = 8; $i < $this->size - 8; $i++) {
            $dark = ($i % 2 === 0);
            $this->modules[6][$i] = $dark;
            $this->modules[$i][6] = $dark;
            $this->reserved[6][$i] = true;
            $this->reserved[$i][6] = true;
        }
    }

    private function placeDarkModule(): void
    {
        // ISO 18004 Section 6.3.7: dark module at (4V + 9, 8) -- one fixed bit,
        // present on every QR Code regardless of version.
        $row = 4 * $this->version + 9;
        $col = 8;
        $this->modules[$row][$col] = true;
        $this->reserved[$row][$col] = true;
    }

    private function placeAlignmentPatterns(): void
    {
        $positions = self::ALIGNMENT_POSITIONS[$this->version] ?? [];
        if ($positions === []) {
            return;
        }
        // ISO 18004 6.5.2: place an alignment pattern at every coordinate pair
        // EXCEPT the three that collide with the finder patterns: (first,first)
        // top-left, (first,last) top-right, (last,first) bottom-left. Patterns
        // that fall on the timing line ARE placed -- the timing pattern is
        // interrupted there. Testing the centre against the reserved map would
        // wrongly drop those, leaving large symbols undecodable.
        $first = $positions[0];
        $last = $positions[count($positions) - 1];
        foreach ($positions as $r) {
            foreach ($positions as $c) {
                if (($r === $first && $c === $first)
                    || ($r === $first && $c === $last)
                    || ($r === $last && $c === $first)) {
                    continue;
                }
                $this->placeAlignmentAt($r, $c);
            }
        }
    }

    private function placeAlignmentAt(int $row, int $col): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $isDark = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                $this->modules[$row + $r][$col + $c] = $isDark;
                $this->reserved[$row + $r][$col + $c] = true;
            }
        }
    }

    private function reserveFormatInfo(): void
    {
        // 15 bits, two copies. Top-left + (top-right + bottom-left).
        for ($i = 0; $i < 9; $i++) {
            $this->reserved[8][$i] = true;
            $this->reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $this->reserved[8][$this->size - 1 - $i] = true;
            $this->reserved[$this->size - 1 - $i][8] = true;
        }
    }

    private function reserveVersionInfo(): void
    {
        // V7+ only. ISO 18004 Section 6.10: two 6x3 blocks of 18 bits each,
        // adjacent to the top-right and bottom-left finders.
        // size - 11 = 3 columns from the finder border (size - 8 - 3).
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $this->reserved[$this->size - 11 + $j][$i] = true;
                $this->reserved[$i][$this->size - 11 + $j] = true;
            }
        }
    }

    /**
     * Place data bits in the zigzag pattern (ISO 18004 Section 6.7.3).
     * Data is consumed bit by bit from `$bits` (a string of '0'/'1').
     */
    public function placeData(string $bits): void
    {
        $bitIdx = 0;
        $size = $this->size;
        // Walk in 2-column strips from right to left, skipping the timing column at x=6.
        for ($x = $size - 1; $x >= 0; $x -= 2) {
            if ($x === 6) {
                $x--; // skip timing column
            }
            // Strip index = (size-1 - x) / 2. Strip 0 (rightmost 2-col strip) walks
            // upward; strips alternate direction from there.
            $upward = ((($size - 1 - $x) >> 1) & 1) === 0;
            for ($y = 0; $y < $size; $y++) {
                $row = $upward ? $size - 1 - $y : $y;
                for ($dx = 0; $dx < 2; $dx++) {
                    $col = $x - $dx;
                    if ($this->reserved[$row][$col]) {
                        continue;
                    }
                    $bit = ($bitIdx < strlen($bits)) ? $bits[$bitIdx] : '0';
                    $this->modules[$row][$col] = $bit === '1';
                    $bitIdx++;
                }
            }
        }
    }
}
