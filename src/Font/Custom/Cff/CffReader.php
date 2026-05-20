<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Parses a CFF1 byte stream (Adobe TN #5176) into a {@see ParsedCff}. The
 * stream is the contents of an OpenType 'CFF ' table; CFF2 is rejected
 * upstream by TtfParser. The reader is strict: exactly one Name INDEX entry,
 * exactly one Top DICT INDEX entry, fail-fast on any unsupported operator
 * or format.
 *
 * Parts 2-5 of this file (Tasks 3-5 in the plan) extend read() to parse
 * String INDEX, GSubrs INDEX, charset, encoding, CharStrings INDEX, Private
 * DICT, local Subrs, FDArray and FDSelect, and populate topDictData.
 *
 * @internal
 */
final class CffReader
{
    /** Top DICT operators (Adobe TN #5176 Table 9). */
    private const array TOP_DICT_OPS = [
        0x00 => 'version',
        0x01 => 'Notice',
        0x02 => 'FullName',
        0x03 => 'FamilyName',
        0x04 => 'Weight',
        0x05 => 'FontBBox',
        0x0d => 'UniqueID',
        0x0e => 'XUID',
        0x0f => 'charset',
        0x10 => 'Encoding',
        0x11 => 'CharStrings',
        0x12 => 'Private',
        0x0c00 => 'Copyright',
        0x0c01 => 'isFixedPitch',
        0x0c02 => 'ItalicAngle',
        0x0c03 => 'UnderlinePosition',
        0x0c04 => 'UnderlineThickness',
        0x0c05 => 'PaintType',
        0x0c06 => 'CharstringType',
        0x0c07 => 'FontMatrix',
        0x0c08 => 'StrokeWidth',
        0x0c14 => 'SyntheticBase',
        0x0c15 => 'PostScript',
        0x0c16 => 'BaseFontName',
        0x0c17 => 'BaseFontBlend',
        0x0c1e => 'ROS',
        0x0c1f => 'CIDFontVersion',
        0x0c20 => 'CIDFontRevision',
        0x0c21 => 'CIDFontType',
        0x0c22 => 'CIDCount',
        0x0c23 => 'UIDBase',
        0x0c24 => 'FDArray',
        0x0c25 => 'FDSelect',
        0x0c26 => 'FontName',
    ];

    public function read(string $cffBytes, string $context): ParsedCff
    {
        $header = $this->readHeader($cffBytes, $context);
        $cursor = $header->hdrSize;

        [$nameEntries, $cursor] = $this->readIndex($cffBytes, $cursor, 'Name', $context);
        if (count($nameEntries) !== 1) {
            throw new PdfException(
                'CFF Name INDEX must contain exactly 1 entry, got ' . count($nameEntries) . " for {$context}",
            );
        }

        [$topDictEntries, $cursor] = $this->readIndex($cffBytes, $cursor, 'Top DICT', $context);
        if (count($topDictEntries) !== 1) {
            throw new PdfException(
                'CFF Top DICT INDEX must contain exactly 1 entry, got ' . count($topDictEntries) . " for {$context}",
            );
        }
        $topDict = $this->parseDict($topDictEntries[0], self::TOP_DICT_OPS, 'Top DICT', $context);

        [$stringEntries, $cursor] = $this->readIndex($cffBytes, $cursor, 'String', $context);
        [$gsubrEntries, $cursor] = $this->readIndex($cffBytes, $cursor, 'GSubrs', $context);

        // topDictData is filled by Tasks 3-5.
        return new ParsedCff(
            header: $header,
            nameIndexEntry: $nameEntries[0],
            topDicts: [$topDict],
            stringIndex: $stringEntries,
            gsubrsIndex: $gsubrEntries,
            topDictData: [],
        );
    }

    private function readHeader(string $bytes, string $context): CffHeader
    {
        if (strlen($bytes) < 4) {
            throw new PdfException("CFF stream too short for header in {$context}");
        }
        $major = ord($bytes[0]);
        $minor = ord($bytes[1]);
        $hdrSize = ord($bytes[2]);
        $offSize = ord($bytes[3]);
        if ($offSize < 1 || $offSize > 4) {
            throw new PdfException("Invalid CFF header offSize {$offSize} for {$context}");
        }
        return new CffHeader($major, $minor, $hdrSize, $offSize);
    }

    /**
     * Adobe TN #5176 section 5: INDEX layout is count(2) | offSize(1) |
     * offset[count+1] | data. Offsets are 1-based relative to (byte just
     * before data).
     *
     * @return array{list<string>, int}
     */
    private function readIndex(string $bytes, int $cursor, string $name, string $context): array
    {
        if ($cursor + 2 > strlen($bytes)) {
            throw new PdfException("CFF INDEX '{$name}' truncated header in {$context}");
        }
        $count = (ord($bytes[$cursor]) << 8) | ord($bytes[$cursor + 1]);
        $cursor += 2;
        if ($count === 0) {
            return [[], $cursor];
        }
        if ($cursor + 1 > strlen($bytes)) {
            throw new PdfException("CFF INDEX '{$name}' truncated offSize in {$context}");
        }
        $offSize = ord($bytes[$cursor]);
        $cursor += 1;
        if ($offSize < 1 || $offSize > 4) {
            throw new PdfException("Invalid CFF INDEX offSize {$offSize} in '{$name}' for {$context}");
        }
        $offsetTableSize = ($count + 1) * $offSize;
        if ($cursor + $offsetTableSize > strlen($bytes)) {
            throw new PdfException("CFF INDEX '{$name}' truncated offset table in {$context}");
        }
        $offsets = [];
        for ($i = 0; $i <= $count; $i++) {
            $offsets[] = $this->readOffset($bytes, $cursor + $i * $offSize, $offSize);
        }
        $cursor += $offsetTableSize;
        $dataStart = $cursor - 1;
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $from = $offsets[$i];
            $to = $offsets[$i + 1];
            if ($from > $to) {
                throw new PdfException("Corrupt CFF INDEX '{$name}' (offset {$from} > {$to}) for {$context}");
            }
            $entries[] = substr($bytes, $dataStart + $from, $to - $from);
        }
        $lastOffset = $offsets[count($offsets) - 1];
        $cursor = $dataStart + $lastOffset;
        return [$entries, $cursor];
    }

    private function readOffset(string $bytes, int $at, int $size): int
    {
        $v = 0;
        for ($i = 0; $i < $size; $i++) {
            $v = ($v << 8) | ord($bytes[$at + $i]);
        }
        return $v;
    }

    /**
     * @param array<int, string> $operators
     * @return array<string, int|float|array<int, int|float>>
     */
    private function parseDict(string $body, array $operators, string $dictName, string $context): array
    {
        $result = [];
        /** @var list<int|float> $operands */
        $operands = [];
        $len = strlen($body);
        $i = 0;
        while ($i < $len) {
            $b0 = ord($body[$i]);
            if ($b0 >= 32 || $b0 === 28 || $b0 === 29) {
                [$operand, $consumed] = $this->parseOperand($body, $i, $dictName, $context);
                $operands[] = $operand;
                $i += $consumed;
                continue;
            }
            if ($b0 === 30) {
                [$operand, $consumed] = $this->parseRealOperand($body, $i, $dictName, $context);
                $operands[] = $operand;
                $i += $consumed;
                continue;
            }
            // operator
            if ($b0 === 12) {
                if ($i + 1 >= $len) {
                    throw new PdfException("Truncated escape operator in CFF {$dictName} for {$context}");
                }
                $code = 0x0c00 | ord($body[$i + 1]);
                $i += 2;
            } else {
                $code = $b0;
                $i += 1;
            }
            if (!isset($operators[$code])) {
                throw new PdfException(sprintf(
                    'Unsupported CFF operator 0x%04X in %s for %s',
                    $code,
                    $dictName,
                    $context,
                ));
            }
            $name = $operators[$code];
            $result[$name] = count($operands) === 1 ? $operands[0] : $operands;
            $operands = [];
        }
        return $result;
    }

    /**
     * @return array{int, int}
     */
    private function parseOperand(string $body, int $i, string $dictName, string $context): array
    {
        $len = strlen($body);
        $b0 = ord($body[$i]);
        if ($b0 >= 32 && $b0 <= 246) {
            return [$b0 - 139, 1];
        }
        if ($b0 >= 247 && $b0 <= 250) {
            if ($i + 1 >= $len) {
                throw new PdfException("Truncated 2-byte operand in CFF {$dictName} for {$context}");
            }
            return [($b0 - 247) * 256 + ord($body[$i + 1]) + 108, 2];
        }
        if ($b0 >= 251 && $b0 <= 254) {
            if ($i + 1 >= $len) {
                throw new PdfException("Truncated 2-byte operand in CFF {$dictName} for {$context}");
            }
            return [-($b0 - 251) * 256 - ord($body[$i + 1]) - 108, 2];
        }
        if ($b0 === 28) {
            if ($i + 2 >= $len) {
                throw new PdfException("Truncated short int operand in CFF {$dictName} for {$context}");
            }
            $v = (ord($body[$i + 1]) << 8) | ord($body[$i + 2]);
            if ($v >= 0x8000) {
                $v -= 0x10000;
            }
            return [$v, 3];
        }
        // b0 == 29: long int
        if ($i + 4 >= $len) {
            throw new PdfException("Truncated long int operand in CFF {$dictName} for {$context}");
        }
        $v = (ord($body[$i + 1]) << 24)
            | (ord($body[$i + 2]) << 16)
            | (ord($body[$i + 3]) << 8)
            | ord($body[$i + 4]);
        if ($v >= 0x80000000) {
            $v -= 0x100000000;
        }
        return [$v, 5];
    }

    /**
     * Adobe TN #5176 Table 5: real number nibbles. End sentinel = 0x0f.
     *
     * @return array{float, int}
     */
    private function parseRealOperand(string $body, int $i, string $dictName, string $context): array
    {
        $start = $i;
        $len = strlen($body);
        $i++; // skip op byte 30
        $s = '';
        $done = false;
        while ($i < $len && !$done) {
            $b = ord($body[$i]);
            foreach ([$b >> 4, $b & 0x0f] as $nib) {
                if ($done) {
                    break;
                }
                if ($nib <= 9) {
                    $s .= (string) $nib;
                } elseif ($nib === 0x0a) {
                    $s .= '.';
                } elseif ($nib === 0x0b) {
                    $s .= 'E';
                } elseif ($nib === 0x0c) {
                    $s .= 'E-';
                } elseif ($nib === 0x0e) {
                    $s .= '-';
                } elseif ($nib === 0x0f) {
                    $done = true;
                } else {
                    throw new PdfException("Invalid nibble in CFF real operand in {$dictName} for {$context}");
                }
            }
            $i++;
        }
        if (!$done) {
            throw new PdfException("Truncated real operand in CFF {$dictName} for {$context}");
        }
        return [(float) $s, $i - $start];
    }
}
