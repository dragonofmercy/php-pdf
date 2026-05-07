<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;

/**
 * QR Code mask functions (ISO 18004 Section 6.8.1) plus penalty scoring
 * (Section 6.8.3.1), format-info bit derivation (Section 6.9 + Table C.1),
 * and version-info bit derivation for V7+ (Section 6.10 + Annex D).
 *
 * @internal
 */
final class Mask
{
    public static function condition(int $maskId, int $row, int $col): bool
    {
        return match ($maskId) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => throw new \InvalidArgumentException("Invalid maskId: {$maskId}"),
        };
    }

    /**
     * Apply mask to a matrix. `reserved[y][x] = true` means do not flip.
     *
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $reserved
     * @return array<int, array<int, bool>>
     */
    public static function apply(array $modules, array $reserved, int $maskId): array
    {
        $out = $modules;
        $size = count($modules);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) {
                    continue;
                }
                if (self::condition($maskId, $r, $c)) {
                    $out[$r][$c] = !$out[$r][$c];
                }
            }
        }
        return $out;
    }

    /**
     * Penalty score (ISO 18004 Section 6.8.3.1) for picking the best mask.
     * Lower = better.
     *
     * @param array<int, array<int, bool>> $modules
     */
    public static function score(array $modules): int
    {
        $size = count($modules);
        $score = 0;

        // Build the transposed matrix once so column passes share the same data.
        $transposed = [];
        for ($c = 0; $c < $size; $c++) {
            $col = [];
            for ($r = 0; $r < $size; $r++) {
                $col[] = $modules[$r][$c];
            }
            $transposed[$c] = $col;
        }

        // N1: rows and columns with 5+ same-colour runs.
        for ($r = 0; $r < $size; $r++) {
            $score += self::scoreRowRuns($modules[$r]);
        }
        for ($c = 0; $c < $size; $c++) {
            $score += self::scoreRowRuns($transposed[$c]);
        }

        // N2: 2x2 same-colour blocks.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $modules[$r][$c];
                if ($modules[$r][$c + 1] === $v && $modules[$r + 1][$c] === $v && $modules[$r + 1][$c + 1] === $v) {
                    $score += 3;
                }
            }
        }

        // N3: 1:1:3:1:1 + 4 light modules on either side (finder-like pattern).
        $pattern = [true, false, true, true, true, false, true];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                if (self::matchFinderRun($modules[$r], $c, $pattern)) {
                    $score += 40;
                }
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                if (self::matchFinderRun($transposed[$c], $r, $pattern)) {
                    $score += 40;
                }
            }
        }

        // N4: dark proportion penalty.
        $dark = 0;
        $total = $size * $size;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($modules[$r][$c]) {
                    $dark++;
                }
            }
        }
        $percent = (int) floor($dark * 100 / $total);
        $deviation = abs($percent - 50);
        $score += intdiv($deviation, 5) * 10;

        return $score;
    }

    /**
     * @param array<int, bool> $line
     */
    private static function scoreRowRuns(array $line): int
    {
        $score = 0;
        $n = count($line);
        $i = 0;
        while ($i < $n) {
            $start = $i;
            $colour = $line[$i];
            while ($i < $n && $line[$i] === $colour) {
                $i++;
            }
            $run = $i - $start;
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
        return $score;
    }

    /**
     * @param array<int, bool> $line
     * @param list<bool> $pattern
     */
    private static function matchFinderRun(array $line, int $offset, array $pattern): bool
    {
        // Pattern is 7 modules (1:1:3:1:1) -- need 4 light modules on either side.
        // Check variant: 4 light to the left, then the 7-module pattern.
        if ($offset >= 4) {
            $allLight = true;
            for ($i = 1; $i <= 4; $i++) {
                if ($line[$offset - $i]) {
                    $allLight = false;
                    break;
                }
            }
            if ($allLight) {
                $match = true;
                for ($i = 0; $i < 7; $i++) {
                    if ($line[$offset + $i] !== $pattern[$i]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    return true;
                }
            }
        }
        // Check variant: 7-module pattern, then 4 light to the right.
        if ($offset + 7 + 4 <= count($line)) {
            $allLight = true;
            for ($i = 0; $i < 4; $i++) {
                if ($line[$offset + 7 + $i]) {
                    $allLight = false;
                    break;
                }
            }
            if ($allLight) {
                $match = true;
                for ($i = 0; $i < 7; $i++) {
                    if ($line[$offset + $i] !== $pattern[$i]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns the 15-bit format info string (BCH-encoded + XOR with spec mask).
     *
     * BCH(15,5) generator polynomial: x^10 + x^8 + x^5 + x^4 + x^2 + x + 1
     * = 0b10100110111 (ISO 18004 Section 6.9.1)
     * Format mask constant: 0b101010000010010 (ISO 18004 Section 6.9.1)
     */
    public static function formatBits(ErrorCorrection $ec, int $maskId): string
    {
        $data5 = ($ec->formatBits() << 3) | $maskId;
        // BCH(15,5) generator: 0b10100110111
        $bch = $data5 << 10;
        for ($i = 4; $i >= 0; $i--) {
            if ($bch & (1 << ($i + 10))) {
                $bch ^= 0b10100110111 << $i;
            }
        }
        $combined = (($data5 << 10) | ($bch & 0x3FF)) ^ 0b101010000010010;
        return str_pad(decbin($combined), 15, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the 18-bit version info string for V7-V40 (BCH-encoded with the
     * generator polynomial x^12 + x^11 + x^10 + x^9 + x^8 + x^5 + x^2 + 1
     * = 0b1111100100101 -- ISO 18004 Annex D). For V1-V6 this method must not
     * be called; the QR has no version-info area.
     */
    public static function versionBits(int $version): string
    {
        // Version data: 6 bits.
        $bch = $version << 12;
        for ($i = 5; $i >= 0; $i--) {
            if ($bch & (1 << ($i + 12))) {
                $bch ^= 0b1111100100101 << $i;
            }
        }
        $combined = ($version << 12) | ($bch & 0xFFF);
        return str_pad(decbin($combined), 18, '0', STR_PAD_LEFT);
    }

    /**
     * Place the 15 format bits into the matrix at canonical positions around the
     * top-left finder and mirrored near the other two finders.
     *
     * @param array<int, array<int, bool>> $modules in/out
     */
    public static function placeFormatBits(array &$modules, ErrorCorrection $ec, int $maskId): void
    {
        $bits = self::formatBits($ec, $maskId);
        $size = count($modules);

        $coords = [
            [0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8],
            [7, 8], [8, 8], [8, 7],
            [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0],
        ];
        $coords2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];
        for ($i = 0; $i < 15; $i++) {
            $bit = $bits[$i] === '1';
            [$r1, $c1] = $coords[$i];
            $modules[$r1][$c1] = $bit;
            [$r2, $c2] = $coords2[$i];
            $modules[$r2][$c2] = $bit;
        }
    }

    /**
     * Place the 18 version bits into the matrix in the two reserved 6x3 blocks
     * (top-right of bottom-left finder and bottom-left of top-right finder), per
     * ISO 18004 Section 6.10.
     *
     * @param array<int, array<int, bool>> $modules in/out
     */
    public static function placeVersionBits(array &$modules, int $version): void
    {
        $bits = self::versionBits($version);
        $size = count($modules);
        $bitIdx = 17; // MSB of 18-bit string at index 0, LSB at index 17

        for ($x = 0; $x < 6; $x++) {
            for ($y = 0; $y < 3; $y++) {
                $bit = $bits[$bitIdx] === '1';
                $bitIdx--;
                // Bottom-left block (rows size-11..size-9, cols 0..5)
                $modules[$size - 11 + $y][$x] = $bit;
                // Top-right block (rows 0..5, cols size-11..size-9), transposed
                $modules[$x][$size - 11 + $y] = $bit;
            }
        }
    }
}
