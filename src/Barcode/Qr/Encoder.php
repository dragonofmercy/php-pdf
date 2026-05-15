<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * QR Code data encoder per ISO/IEC 18004.
 *
 * Detects the most efficient mode (numeric, alphanumeric, byte), picks the
 * smallest version (V1-V40, the full ISO 18004 range) that fits the data +
 * ECC overhead,
 * builds the bitstream (mode indicator + char count + data + terminator +
 * padding), splits into blocks, computes Reed-Solomon EC per block, and
 * interleaves the blocks into the final codeword sequence.
 *
 * Kanji mode is not supported (Phase 3 prerequisite).
 *
 * @internal
 */
final class Encoder
{
    /** Maximum supported QR version. V40 is the upper bound of ISO/IEC 18004. */
    private const int MAX_VERSION = 40;

    /** Alphanumeric character set per ISO 18004 Table 5. */
    private const string ALPHANUM_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * ISO 18004 Annex M Table 9 -- block grouping for V1-V40.
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
        11 => [
            0 => [324, [[4, 81, 20]]],
            1 => [254, [[1, 50, 30], [4, 51, 30]]],
            2 => [180, [[4, 22, 28], [4, 23, 28]]],
            3 => [140, [[3, 12, 24], [8, 13, 24]]],
        ],
        12 => [
            0 => [370, [[2, 92, 24], [2, 93, 24]]],
            1 => [290, [[6, 36, 22], [2, 37, 22]]],
            2 => [206, [[4, 20, 26], [6, 21, 26]]],
            3 => [158, [[7, 14, 28], [4, 15, 28]]],
        ],
        13 => [
            0 => [428, [[4, 107, 26]]],
            1 => [334, [[8, 37, 22], [1, 38, 22]]],
            2 => [244, [[8, 20, 24], [4, 21, 24]]],
            3 => [180, [[12, 11, 22], [4, 12, 22]]],
        ],
        14 => [
            0 => [461, [[3, 115, 30], [1, 116, 30]]],
            1 => [365, [[4, 40, 24], [5, 41, 24]]],
            2 => [261, [[11, 16, 20], [5, 17, 20]]],
            3 => [197, [[11, 12, 24], [5, 13, 24]]],
        ],
        15 => [
            0 => [523, [[5, 87, 22], [1, 88, 22]]],
            1 => [415, [[5, 41, 24], [5, 42, 24]]],
            2 => [295, [[5, 24, 30], [7, 25, 30]]],
            3 => [223, [[11, 12, 24], [7, 13, 24]]],
        ],
        16 => [
            0 => [589, [[5, 98, 24], [1, 99, 24]]],
            1 => [453, [[7, 45, 28], [3, 46, 28]]],
            2 => [325, [[15, 19, 24], [2, 20, 24]]],
            3 => [253, [[3, 15, 30], [13, 16, 30]]],
        ],
        17 => [
            0 => [647, [[1, 107, 28], [5, 108, 28]]],
            1 => [507, [[10, 46, 28], [1, 47, 28]]],
            2 => [367, [[1, 22, 28], [15, 23, 28]]],
            3 => [283, [[2, 14, 28], [17, 15, 28]]],
        ],
        18 => [
            0 => [721, [[5, 120, 30], [1, 121, 30]]],
            1 => [563, [[9, 43, 26], [4, 44, 26]]],
            2 => [397, [[17, 22, 28], [1, 23, 28]]],
            3 => [313, [[2, 14, 28], [19, 15, 28]]],
        ],
        19 => [
            0 => [795, [[3, 113, 28], [4, 114, 28]]],
            1 => [627, [[3, 44, 26], [11, 45, 26]]],
            2 => [445, [[17, 21, 26], [4, 22, 26]]],
            3 => [341, [[9, 13, 26], [16, 14, 26]]],
        ],
        20 => [
            0 => [861, [[3, 107, 28], [5, 108, 28]]],
            1 => [669, [[3, 41, 26], [13, 42, 26]]],
            2 => [485, [[15, 24, 30], [5, 25, 30]]],
            3 => [385, [[15, 15, 28], [10, 16, 28]]],
        ],
        21 => [
            0 => [932, [[4, 116, 28], [4, 117, 28]]],
            1 => [714, [[17, 42, 26]]],
            2 => [512, [[17, 22, 28], [6, 23, 28]]],
            3 => [406, [[19, 16, 30], [6, 17, 30]]],
        ],
        22 => [
            0 => [1006, [[2, 111, 28], [7, 112, 28]]],
            1 => [782, [[17, 46, 28]]],
            2 => [568, [[7, 24, 30], [16, 25, 30]]],
            3 => [442, [[34, 13, 24]]],
        ],
        23 => [
            0 => [1094, [[4, 121, 30], [5, 122, 30]]],
            1 => [860, [[4, 47, 28], [14, 48, 28]]],
            2 => [614, [[11, 24, 30], [14, 25, 30]]],
            3 => [464, [[16, 15, 30], [14, 16, 30]]],
        ],
        24 => [
            0 => [1174, [[6, 117, 30], [4, 118, 30]]],
            1 => [914, [[6, 45, 28], [14, 46, 28]]],
            2 => [664, [[11, 24, 30], [16, 25, 30]]],
            3 => [514, [[30, 16, 30], [2, 17, 30]]],
        ],
        25 => [
            0 => [1276, [[8, 106, 26], [4, 107, 26]]],
            1 => [1000, [[8, 47, 28], [13, 48, 28]]],
            2 => [718, [[7, 24, 30], [22, 25, 30]]],
            3 => [538, [[22, 15, 30], [13, 16, 30]]],
        ],
        26 => [
            0 => [1370, [[10, 114, 28], [2, 115, 28]]],
            1 => [1062, [[19, 46, 28], [4, 47, 28]]],
            2 => [754, [[28, 22, 28], [6, 23, 28]]],
            3 => [596, [[33, 16, 30], [4, 17, 30]]],
        ],
        27 => [
            0 => [1468, [[8, 122, 30], [4, 123, 30]]],
            1 => [1128, [[22, 45, 28], [3, 46, 28]]],
            2 => [808, [[8, 23, 30], [26, 24, 30]]],
            3 => [628, [[12, 15, 30], [28, 16, 30]]],
        ],
        28 => [
            0 => [1531, [[3, 117, 30], [10, 118, 30]]],
            1 => [1193, [[3, 45, 28], [23, 46, 28]]],
            2 => [871, [[4, 24, 30], [31, 25, 30]]],
            3 => [661, [[11, 15, 30], [31, 16, 30]]],
        ],
        29 => [
            0 => [1631, [[7, 116, 30], [7, 117, 30]]],
            1 => [1267, [[21, 45, 28], [7, 46, 28]]],
            2 => [911, [[1, 23, 30], [37, 24, 30]]],
            3 => [701, [[19, 15, 30], [26, 16, 30]]],
        ],
        30 => [
            0 => [1735, [[5, 115, 30], [10, 116, 30]]],
            1 => [1373, [[19, 47, 28], [10, 48, 28]]],
            2 => [985, [[15, 24, 30], [25, 25, 30]]],
            3 => [745, [[23, 15, 30], [25, 16, 30]]],
        ],
        31 => [
            0 => [1843, [[13, 115, 30], [3, 116, 30]]],
            1 => [1455, [[2, 46, 28], [29, 47, 28]]],
            2 => [1033, [[42, 24, 30], [1, 25, 30]]],
            3 => [793, [[23, 15, 30], [28, 16, 30]]],
        ],
        32 => [
            0 => [1955, [[17, 115, 30]]],
            1 => [1541, [[10, 46, 28], [23, 47, 28]]],
            2 => [1115, [[10, 24, 30], [35, 25, 30]]],
            3 => [845, [[19, 15, 30], [35, 16, 30]]],
        ],
        33 => [
            0 => [2071, [[17, 115, 30], [1, 116, 30]]],
            1 => [1631, [[14, 46, 28], [21, 47, 28]]],
            2 => [1171, [[29, 24, 30], [19, 25, 30]]],
            3 => [901, [[11, 15, 30], [46, 16, 30]]],
        ],
        34 => [
            0 => [2191, [[13, 115, 30], [6, 116, 30]]],
            1 => [1725, [[14, 46, 28], [23, 47, 28]]],
            2 => [1231, [[44, 24, 30], [7, 25, 30]]],
            3 => [961, [[59, 16, 30], [1, 17, 30]]],
        ],
        35 => [
            0 => [2306, [[12, 121, 30], [7, 122, 30]]],
            1 => [1812, [[12, 47, 28], [26, 48, 28]]],
            2 => [1286, [[39, 24, 30], [14, 25, 30]]],
            3 => [986, [[22, 15, 30], [41, 16, 30]]],
        ],
        36 => [
            0 => [2434, [[6, 121, 30], [14, 122, 30]]],
            1 => [1914, [[6, 47, 28], [34, 48, 28]]],
            2 => [1354, [[46, 24, 30], [10, 25, 30]]],
            3 => [1054, [[2, 15, 30], [64, 16, 30]]],
        ],
        37 => [
            0 => [2566, [[17, 122, 30], [4, 123, 30]]],
            1 => [1992, [[29, 46, 28], [14, 47, 28]]],
            2 => [1426, [[49, 24, 30], [10, 25, 30]]],
            3 => [1096, [[24, 15, 30], [46, 16, 30]]],
        ],
        38 => [
            0 => [2702, [[4, 122, 30], [18, 123, 30]]],
            1 => [2102, [[13, 46, 28], [32, 47, 28]]],
            2 => [1502, [[48, 24, 30], [14, 25, 30]]],
            3 => [1142, [[42, 15, 30], [32, 16, 30]]],
        ],
        39 => [
            0 => [2812, [[20, 117, 30], [4, 118, 30]]],
            1 => [2216, [[40, 47, 28], [7, 48, 28]]],
            2 => [1582, [[43, 24, 30], [22, 25, 30]]],
            3 => [1222, [[10, 15, 30], [67, 16, 30]]],
        ],
        40 => [
            0 => [2956, [[19, 118, 30], [6, 119, 30]]],
            1 => [2334, [[18, 47, 28], [31, 48, 28]]],
            2 => [1666, [[34, 24, 30], [34, 25, 30]]],
            3 => [1276, [[20, 15, 30], [61, 16, 30]]],
        ],
    ];

    public static function detectMode(string $data): QrMode
    {
        if ($data === '') {
            return QrMode::Byte;
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
        if ($isNumeric) return QrMode::Numeric;
        if ($isAlphanumeric) return QrMode::Alphanumeric;
        return QrMode::Byte;
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

    private static function pickVersion(string $data, QrMode $mode, ErrorCorrection $ec): int
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

    private static function bitsNeeded(string $data, QrMode $mode, int $version): int
    {
        $charCountBits = self::charCountBits($mode, $version);
        $dataBits = match ($mode) {
            QrMode::Numeric => self::numericBits(strlen($data)),
            QrMode::Alphanumeric => self::alphanumericBits(strlen($data)),
            QrMode::Byte => 8 * strlen($data),
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

    private static function charCountBits(QrMode $mode, int $version): int
    {
        // ISO 18004 Table 3.
        if ($version <= 9) {
            return match ($mode) {
                QrMode::Numeric => 10,
                QrMode::Alphanumeric => 9,
                QrMode::Byte => 8,
            };
        }
        if ($version <= 26) {
            return match ($mode) {
                QrMode::Numeric => 12,
                QrMode::Alphanumeric => 11,
                QrMode::Byte => 16,
            };
        }
        // V27-V40:
        return match ($mode) {
            QrMode::Numeric => 14,
            QrMode::Alphanumeric => 13,
            QrMode::Byte => 16,
        };
    }

    private static function buildBitstream(string $data, QrMode $mode, int $version, ErrorCorrection $ec): string
    {
        $len = strlen($data);
        $bits = '';
        $bits .= $mode->indicator();
        $bits .= str_pad(decbin($len), self::charCountBits($mode, $version), '0', STR_PAD_LEFT);

        match ($mode) {
            QrMode::Numeric => $bits .= self::encodeNumericBits($data, $len),
            QrMode::Alphanumeric => $bits .= self::encodeAlphanumericBits($data, $len),
            QrMode::Byte => $bits .= self::encodeByteBits($data, $len),
        };

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

    private static function encodeNumericBits(string $data, int $len): string
    {
        $bits = '';
        for ($i = 0; $i < $len; $i += 3) {
            $chunk = substr($data, $i, 3);
            $chunkLen = strlen($chunk);
            $bitsForChunk = $chunkLen === 3 ? 10 : ($chunkLen === 2 ? 7 : 4);
            $bits .= str_pad(decbin((int)$chunk), $bitsForChunk, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

    private static function encodeAlphanumericBits(string $data, int $len): string
    {
        $bits = '';
        for ($i = 0; $i < $len; $i += 2) {
            if ($i + 1 < $len) {
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
        return $bits;
    }

    private static function encodeByteBits(string $data, int $len): string
    {
        $bits = '';
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
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
