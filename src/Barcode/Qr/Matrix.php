<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

/**
 * QR Code matrix container + builder. The matrix is a square grid of
 * boolean modules (true = dark). `reserved[y][x]` flags whether a position
 * is a function pattern (must not be overwritten by data or masking).
 *
 * Supports versions 1-10 in this release.
 *
 * @internal
 */
final class Matrix
{
    /**
     * Alignment pattern centre coordinates per ISO 18004 Annex E (Table E.1).
     * Indexed by version 1..10. Cartesian product of the listed positions
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
        foreach ($positions as $r) {
            foreach ($positions as $c) {
                if ($this->reserved[$r][$c]) {
                    continue; // overlaps a finder
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
