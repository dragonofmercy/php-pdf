<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * QR Code data encoder per ISO/IEC 18004.
 *
 * Detects the most efficient mode (numeric, alphanumeric, byte), picks the
 * smallest version (V1-V10 in this release) that fits the data + ECC overhead,
 * builds the bitstream (mode indicator + char count + data + terminator +
 * padding), splits into blocks, computes Reed-Solomon EC per block, and
 * interleaves the blocks into the final codeword sequence.
 *
 * Kanji mode is not supported (Phase 3 prerequisite). Versions V11-V40 are
 * not supported in this release.
 *
 * @internal
 */
final class Encoder
{
    /** Maximum supported QR version in this release. V11-V40 are out of scope. */
    private const int MAX_VERSION = 10;

    /** Alphanumeric character set per ISO 18004 Table 5. */
    private const string ALPHANUM_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * ISO 18004 Annex M Table 9 -- block grouping for V1-V10.
     * Format: CAPACITY_TABLE[version][ecLevelIndex] = [totalDataCodewords, [[blockCount, dataPerBlock, ecPerBlock], ...]]
     * ecLevelIndex: L=0, M=1, Q=2, H=3.
     *
     * @var array<int, array<int, array{int, list<array{int, int, int}>}>>
     */
    private const array CAPACITY_TABLE = [
        1 => [
            0 => [19, [[1, 19, 7]]],
            1 => [16, [[1, 16, 10]]],
            2 => [13, [[1, 13, 13]]],
            3 => [9,  [[1, 9, 17]]],
        ],
        2 => [
            0 => [34, [[1, 34, 10]]],
            1 => [28, [[1, 28, 16]]],
            2 => [22, [[1, 22, 22]]],
            3 => [16, [[1, 16, 28]]],
        ],
        3 => [
            0 => [55, [[1, 55, 15]]],
            1 => [44, [[1, 44, 26]]],
            2 => [34, [[2, 17, 18]]],
            3 => [26, [[2, 13, 22]]],
        ],
        4 => [
            0 => [80, [[1, 80, 20]]],
            1 => [64, [[2, 32, 18]]],
            2 => [48, [[2, 24, 26]]],
            3 => [36, [[4, 9, 16]]],
        ],
        5 => [
            0 => [108, [[1, 108, 26]]],
            1 => [86,  [[2, 43, 24]]],
            2 => [62,  [[2, 15, 18], [2, 16, 18]]],
            3 => [46,  [[2, 11, 22], [2, 12, 22]]],
        ],
        6 => [
            0 => [136, [[2, 68, 18]]],
            1 => [108, [[4, 27, 16]]],
            2 => [76,  [[4, 19, 24]]],
            3 => [60,  [[4, 15, 28]]],
        ],
        7 => [
            0 => [156, [[2, 78, 20]]],
            1 => [124, [[4, 31, 18]]],
            2 => [88,  [[2, 14, 18], [4, 15, 18]]],
            3 => [66,  [[4, 13, 26], [1, 14, 26]]],
        ],
        8 => [
            0 => [194, [[2, 97, 24]]],
            1 => [154, [[2, 38, 22], [2, 39, 22]]],
            2 => [110, [[4, 18, 22], [2, 19, 22]]],
            3 => [86,  [[4, 14, 26], [2, 15, 26]]],
        ],
        9 => [
            0 => [232, [[2, 116, 30]]],
            1 => [182, [[3, 36, 22], [2, 37, 22]]],
            2 => [132, [[4, 16, 20], [4, 17, 20]]],
            3 => [100, [[4, 12, 24], [4, 13, 24]]],
        ],
        10 => [
            0 => [274, [[2, 68, 18], [2, 69, 18]]],
            1 => [216, [[4, 43, 26], [1, 44, 26]]],
            2 => [154, [[6, 19, 24], [2, 20, 24]]],
            3 => [122, [[6, 15, 28], [2, 16, 28]]],
        ],
    ];

    public static function detectMode(string $data): string
    {
        if ($data === '') {
            return 'byte';
        }
        $isNumeric = true;
        $isAlphanumeric = true;
        $alphanum = self::ALPHANUM_CHARSET;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $c = $data[$i];
            if ($c < '0' || $c > '9') {
                $isNumeric = false;
            }
            if (strpos($alphanum, $c) === false) {
                $isAlphanumeric = false;
            }
        }
        if ($isNumeric) return 'numeric';
        if ($isAlphanumeric) return 'alphanumeric';
        return 'byte';
    }

    public static function encode(string $data, ErrorCorrection $ec): EncodeResult
    {
        $mode = self::detectMode($data);
        $version = self::pickVersion($data, $mode, $ec);
        $bits = self::buildBitstream($data, $mode, $version, $ec);

        [$totalDataCodewords, $blockSpec] = self::CAPACITY_TABLE[$version][$ec->ordinal()];
        $codewords = self::bitsToBytes($bits, $totalDataCodewords);

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($blockSpec as [$count, $dataPerBlock, $ecPerBlock]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($codewords, $offset, $dataPerBlock);
                $offset += $dataPerBlock;
                $dataBlocks[] = $block;
                $ecBlocks[] = ReedSolomon::encode($block, $ecPerBlock);
            }
        }

        // Interleave (ISO 18004 Section 6.6): row-major across blocks, data first then EC.
        $maxData = 0;
        foreach ($dataBlocks as $blk) {
            $maxData = max($maxData, count($blk));
        }
        $maxEc = 0;
        foreach ($ecBlocks as $blk) {
            $maxEc = max($maxEc, count($blk));
        }

        $final = [];
        for ($col = 0; $col < $maxData; $col++) {
            foreach ($dataBlocks as $blk) {
                if (isset($blk[$col])) {
                    $final[] = $blk[$col];
                }
            }
        }
        for ($col = 0; $col < $maxEc; $col++) {
            foreach ($ecBlocks as $blk) {
                if (isset($blk[$col])) {
                    $final[] = $blk[$col];
                }
            }
        }

        return new EncodeResult($version, $ec, $final);
    }

    private static function pickVersion(string $data, string $mode, ErrorCorrection $ec): int
    {
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $totalData = self::CAPACITY_TABLE[$v][$ec->ordinal()][0];
            $bitsNeeded = self::bitsNeeded($data, $mode, $v);
            if (intdiv($bitsNeeded + 7, 8) <= $totalData) {
                return $v;
            }
        }
        $maxAtCap = self::CAPACITY_TABLE[self::MAX_VERSION][$ec->ordinal()][0];
        $maxL = self::CAPACITY_TABLE[self::MAX_VERSION][ErrorCorrection::L->ordinal()][0];
        throw new PdfException(sprintf(
            'QR code data (%d bytes) exceeds capacity of V%d-%s (%d bytes). Try a lower error correction level (L=%d bytes). Versions V11-V40 are not supported in this release.',
            strlen($data), self::MAX_VERSION, $ec->value, $maxAtCap, $maxL,
        ));
    }

    private static function bitsNeeded(string $data, string $mode, int $version): int
    {
        $charCountBits = self::charCountBits($mode, $version);
        $dataBits = match ($mode) {
            'numeric' => self::numericBits(strlen($data)),
            'alphanumeric' => self::alphanumericBits(strlen($data)),
            'byte' => 8 * strlen($data),
            default => throw new PdfException("Unknown QR mode: {$mode}"),
        };
        return 4 + $charCountBits + $dataBits;
    }

    private static function numericBits(int $n): int
    {
        $full = intdiv($n, 3);
        $rem = $n % 3;
        return 10 * $full + ($rem === 2 ? 7 : ($rem === 1 ? 4 : 0));
    }

    private static function alphanumericBits(int $n): int
    {
        $pairs = intdiv($n, 2);
        $rem = $n % 2;
        return 11 * $pairs + ($rem ? 6 : 0);
    }

    private static function charCountBits(string $mode, int $version): int
    {
        // ISO 18004 Table 3. V1-V9 use the first group, V10 uses the V10-V26 row.
        if ($version <= 9) {
            return match ($mode) {
                'numeric' => 10,
                'alphanumeric' => 9,
                'byte' => 8,
                default => throw new PdfException("Unknown QR mode: {$mode}"),
            };
        }
        // V10+ (here only V10):
        return match ($mode) {
            'numeric' => 12,
            'alphanumeric' => 11,
            'byte' => 16,
            default => throw new PdfException("Unknown QR mode: {$mode}"),
        };
    }

    private static function buildBitstream(string $data, string $mode, int $version, ErrorCorrection $ec): string
    {
        $bits = '';
        $modeIndicator = match ($mode) {
            'numeric' => '0001',
            'alphanumeric' => '0010',
            'byte' => '0100',
            default => throw new PdfException("Unknown QR mode: {$mode}"),
        };
        $bits .= $modeIndicator;
        $bits .= str_pad(decbin(strlen($data)), self::charCountBits($mode, $version), '0', STR_PAD_LEFT);

        switch ($mode) {
            case 'numeric':
                for ($i = 0; $i < strlen($data); $i += 3) {
                    $chunk = substr($data, $i, 3);
                    $bitsForChunk = strlen($chunk) === 3 ? 10 : (strlen($chunk) === 2 ? 7 : 4);
                    $bits .= str_pad(decbin((int)$chunk), $bitsForChunk, '0', STR_PAD_LEFT);
                }
                break;
            case 'alphanumeric':
                for ($i = 0; $i < strlen($data); $i += 2) {
                    if ($i + 1 < strlen($data)) {
                        $v1 = strpos(self::ALPHANUM_CHARSET, $data[$i]);
                        $v2 = strpos(self::ALPHANUM_CHARSET, $data[$i + 1]);
                        if ($v1 === false || $v2 === false) {
                            throw new PdfException('Internal error: alphanumeric mode received non-alphanumeric byte.');
                        }
                        $bits .= str_pad(decbin($v1 * 45 + $v2), 11, '0', STR_PAD_LEFT);
                    } else {
                        $v1 = strpos(self::ALPHANUM_CHARSET, $data[$i]);
                        if ($v1 === false) {
                            throw new PdfException('Internal error: alphanumeric mode received non-alphanumeric byte.');
                        }
                        $bits .= str_pad(decbin($v1), 6, '0', STR_PAD_LEFT);
                    }
                }
                break;
            case 'byte':
                for ($i = 0; $i < strlen($data); $i++) {
                    $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
                }
                break;
        }

        // Terminator: up to 4 zero bits, capped at remaining capacity.
        $totalDataCodewords = self::CAPACITY_TABLE[$version][$ec->ordinal()][0];
        $totalDataBits = $totalDataCodewords * 8;
        $remaining = $totalDataBits - strlen($bits);
        $terminatorLen = max(0, min(4, $remaining));
        $bits .= str_repeat('0', $terminatorLen);

        // Pad to byte boundary.
        $rem = strlen($bits) % 8;
        if ($rem !== 0) {
            $bits .= str_repeat('0', 8 - $rem);
        }

        // Pad bytes alternating 0xEC, 0x11 until full.
        $padBytes = intdiv($totalDataBits - strlen($bits), 8);
        for ($i = 0; $i < $padBytes; $i++) {
            $bits .= ($i % 2 === 0) ? '11101100' : '00010001';
        }
        return $bits;
    }

    /**
     * @return list<int<0, 255>>
     */
    private static function bitsToBytes(string $bits, int $totalDataCodewords): array
    {
        /** @var list<int<0, 255>> $bytes */
        $bytes = [];
        for ($i = 0; $i < $totalDataCodewords; $i++) {
            /** @var int<0, 255> $byte */
            $byte = (int) bindec(substr($bits, $i * 8, 8)) & 0xFF;
            $bytes[] = $byte;
        }
        return $bytes;
    }
}
