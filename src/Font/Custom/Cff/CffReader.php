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

    /** Private DICT operators (Adobe TN #5176 Table 23). */
    private const array PRIVATE_DICT_OPS = [
        0x06 => 'BlueValues',
        0x07 => 'OtherBlues',
        0x08 => 'FamilyBlues',
        0x09 => 'FamilyOtherBlues',
        0x0a => 'StdHW',
        0x0b => 'StdVW',
        0x13 => 'Subrs',
        0x14 => 'defaultWidthX',
        0x15 => 'nominalWidthX',
        0x0c09 => 'BlueScale',
        0x0c0a => 'BlueShift',
        0x0c0b => 'BlueFuzz',
        0x0c0c => 'StemSnapH',
        0x0c0d => 'StemSnapV',
        0x0c0e => 'ForceBold',
        0x0c11 => 'LanguageGroup',
        0x0c12 => 'ExpansionFactor',
        0x0c13 => 'initialRandomSeed',
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

        $charStringsOffset = $this->requireIntOperator($topDict, 'CharStrings', $context);
        [$csEntries, ] = $this->readIndex($cffBytes, $charStringsOffset, 'CharStrings', $context);
        $numGlyphs = count($csEntries);
        $glyphsMap = [];
        foreach ($csEntries as $gid => $entry) {
            $glyphsMap[$gid] = $entry;
        }

        $charsetOffset = $this->requireIntOperator($topDict, 'charset', $context);
        $isCidKeyed = isset($topDict['ROS']);
        $charset = $this->readCharset($cffBytes, $charsetOffset, $numGlyphs, $context);

        $encoding = null;
        if (!$isCidKeyed && isset($topDict['Encoding'])) {
            $encodingOff = $topDict['Encoding'];
            if (!is_int($encodingOff)) {
                throw new PdfException("CFF Encoding operand must be int for {$context}");
            }
            $encoding = $this->readEncoding($cffBytes, $encodingOff, $context);
        }

        $namePrivate = null;
        $cidKeyed = null;
        if ($isCidKeyed) {
            $fdaOff = $this->requireIntOperator($topDict, 'FDArray', $context);
            $fdsOff = $this->requireIntOperator($topDict, 'FDSelect', $context);
            $cidKeyed = $this->readCidKeyedPayload($cffBytes, $fdaOff, $fdsOff, $numGlyphs, $context);
        } else {
            $privOps = $topDict['Private'] ?? null;
            if ($privOps !== null) {
                if (!is_array($privOps) || count($privOps) !== 2) {
                    throw new PdfException("CFF Private operator must be [size, offset] for {$context}");
                }
                $size = $privOps[0];
                $offset = $privOps[1];
                if (!is_int($size) || !is_int($offset)) {
                    throw new PdfException("CFF Private size/offset must be int for {$context}");
                }
                $namePrivate = $this->readPrivateAndSubrs($cffBytes, $offset, $size, $context);
            }
        }

        $topData = new CffTopDictData(
            charset: $charset,
            encoding: $encoding,
            charStrings: new CffCharStrings(glyphs: $glyphsMap, numGlyphs: $numGlyphs),
            namePrivate: $namePrivate,
            cidKeyed: $cidKeyed,
        );

        return new ParsedCff(
            header: $header,
            nameIndexEntry: $nameEntries[0],
            topDicts: [$topDict],
            stringIndex: $stringEntries,
            gsubrsIndex: $gsubrEntries,
            topDictData: [$topData],
        );
    }

    private function readEncoding(string $bytes, int $offset, string $context): CffEncoding
    {
        $totalLen = strlen($bytes);
        if ($offset >= $totalLen) {
            throw new PdfException("CFF encoding offset {$offset} out of bounds for {$context}");
        }
        $formatByte = ord($bytes[$offset]);
        $format = $formatByte & 0x7f;
        $hasSup = ($formatByte & 0x80) !== 0;
        // Read the count byte that follows the format byte (used by format 0 = nCodes and format 1 = nRanges).
        if ($offset + 1 >= $totalLen) {
            throw new PdfException("CFF encoding truncated count byte at {$offset} for {$context}");
        }
        $countByte = ord($bytes[$offset + 1]);
        if ($format === 0) {
            $len = 2 + $countByte;
        } elseif ($format === 1) {
            $len = 2 + $countByte * 2;
        } else {
            throw new PdfException("Unsupported CFF encoding format {$format} for {$context}");
        }
        if ($hasSup) {
            if ($offset + $len >= $totalLen) {
                throw new PdfException("CFF encoding supplemental count out of bounds for {$context}");
            }
            $nSups = ord($bytes[$offset + $len]);
            $len += 1 + $nSups * 3;
        }
        if ($offset + $len > $totalLen) {
            throw new PdfException("CFF encoding payload out of bounds for {$context}");
        }
        return new CffEncoding(substr($bytes, $offset, $len));
    }

    private function readPrivateAndSubrs(string $bytes, int $offset, int $size, string $context): CffNameKeyedPrivate
    {
        if ($offset + $size > strlen($bytes)) {
            throw new PdfException("CFF Private DICT out of bounds for {$context}");
        }
        $privateBody = substr($bytes, $offset, $size);
        $privateDict = $this->parseDict($privateBody, self::PRIVATE_DICT_OPS, 'Private DICT', $context);
        $localSubrs = [];
        if (isset($privateDict['Subrs'])) {
            $subrsRel = $privateDict['Subrs'];
            if (!is_int($subrsRel)) {
                throw new PdfException("CFF Private Subrs operand must be int for {$context}");
            }
            [$localSubrs, ] = $this->readIndex($bytes, $offset + $subrsRel, 'local Subrs', $context);
        }
        return new CffNameKeyedPrivate($privateDict, $localSubrs);
    }

    private function readCidKeyedPayload(
        string $bytes,
        int $fdaOffset,
        int $fdsOffset,
        int $numGlyphs,
        string $context,
    ): CffCidKeyed {
        [$fdEntries, ] = $this->readIndex($bytes, $fdaOffset, 'FDArray', $context);
        $fontDicts = [];
        $fdPrivates = [];
        foreach ($fdEntries as $i => $fdBody) {
            $fontDict = $this->parseDict($fdBody, self::TOP_DICT_OPS, "Font DICT #{$i}", $context);
            $fontDicts[] = $fontDict;
            if (!isset($fontDict['Private'])) {
                throw new PdfException("CFF CID Font DICT #{$i} missing Private operator for {$context}");
            }
            $privOps = $fontDict['Private'];
            if (!is_array($privOps) || count($privOps) !== 2) {
                throw new PdfException("CFF Font DICT Private must be [size, offset] for {$context}");
            }
            $size = $privOps[0];
            $offset = $privOps[1];
            if (!is_int($size) || !is_int($offset)) {
                throw new PdfException("CFF Font DICT Private size/offset must be int for {$context}");
            }
            $fdPrivates[] = $this->readPrivateAndSubrs($bytes, $offset, $size, $context);
        }
        [$fdSelect, $fdSelectRawBytes] = $this->readFdSelect($bytes, $fdsOffset, $numGlyphs, $context);
        $format = ord($bytes[$fdsOffset]);
        return new CffCidKeyed($fontDicts, $fdPrivates, $fdSelect, $format, $fdSelectRawBytes);
    }

    /**
     * @return array{0: array<int, int>, 1: string} GID -> FD index map plus raw FDSelect bytes
     */
    private function readFdSelect(string $bytes, int $offset, int $numGlyphs, string $context): array
    {
        $totalLen = strlen($bytes);
        if ($offset >= $totalLen) {
            throw new PdfException("CFF FDSelect offset {$offset} out of bounds for {$context}");
        }
        $format = ord($bytes[$offset]);
        $cursor = $offset + 1;
        $map = [];
        if ($format === 0) {
            if ($cursor + $numGlyphs > $totalLen) {
                throw new PdfException("CFF FDSelect format 0 truncated for {$context}");
            }
            for ($g = 0; $g < $numGlyphs; $g++) {
                $map[$g] = ord($bytes[$cursor + $g]);
            }
            $cursor += $numGlyphs;
            return [$map, substr($bytes, $offset, $cursor - $offset)];
        }
        if ($format === 3) {
            if ($cursor + 2 > $totalLen) {
                throw new PdfException("CFF FDSelect format 3 truncated nRanges for {$context}");
            }
            $nRanges = (ord($bytes[$cursor]) << 8) | ord($bytes[$cursor + 1]);
            $cursor += 2;
            // Need nRanges * 3 bytes for ranges + 2 bytes sentinel
            if ($cursor + $nRanges * 3 + 2 > $totalLen) {
                throw new PdfException("CFF FDSelect format 3 truncated ranges for {$context}");
            }
            $ranges = [];
            for ($r = 0; $r < $nRanges; $r++) {
                $first = (ord($bytes[$cursor]) << 8) | ord($bytes[$cursor + 1]);
                $fd = ord($bytes[$cursor + 2]);
                $cursor += 3;
                $ranges[] = [$first, $fd];
            }
            $sentinel = (ord($bytes[$cursor]) << 8) | ord($bytes[$cursor + 1]);
            $cursor += 2;
            // Now expand ranges
            for ($r = 0; $r < $nRanges; $r++) {
                $first = $ranges[$r][0];
                $fd = $ranges[$r][1];
                $nextFirst = ($r + 1 < $nRanges) ? $ranges[$r + 1][0] : $sentinel;
                for ($g = $first; $g < $nextFirst && $g < $numGlyphs; $g++) {
                    $map[$g] = $fd;
                }
            }
            return [$map, substr($bytes, $offset, $cursor - $offset)];
        }
        throw new PdfException("Unsupported CFF FDSelect format {$format} for {$context}");
    }

    /**
     * @param array<string, int|float|array<int, int|float>> $dict
     */
    private function requireIntOperator(array $dict, string $name, string $context): int
    {
        if (!isset($dict[$name])) {
            throw new PdfException("CFF Top DICT missing required '{$name}' for {$context}");
        }
        $v = $dict[$name];
        if (!is_int($v)) {
            throw new PdfException("CFF Top DICT operator '{$name}' must be an integer offset for {$context}");
        }
        return $v;
    }

    private function readCharset(string $bytes, int $offset, int $numGlyphs, string $context): CffCharset
    {
        $len = strlen($bytes);
        if ($offset < 0 || $offset >= $len) {
            throw new PdfException("CFF charset offset {$offset} out of bounds for {$context}");
        }
        $format = ord($bytes[$offset]);
        $cursor = $offset + 1;
        $map = [0 => 0]; // GID 0 = .notdef implicitly
        if ($format === 0) {
            for ($gid = 1; $gid < $numGlyphs; $gid++) {
                if ($cursor + 2 > $len) {
                    throw new PdfException("Truncated CFF charset format 0 for {$context}");
                }
                $map[$gid] = (ord($bytes[$cursor]) << 8) | ord($bytes[$cursor + 1]);
                $cursor += 2;
            }
            return new CffCharset($map, 0, substr($bytes, $offset, $cursor - $offset));
        }
        if ($format === 1 || $format === 2) {
            $nLeftSize = $format === 1 ? 1 : 2;
            $gid = 1;
            while ($gid < $numGlyphs) {
                if ($cursor + 2 + $nLeftSize > $len) {
                    throw new PdfException("Truncated CFF charset format {$format} for {$context}");
                }
                $first = (ord($bytes[$cursor]) << 8) | ord($bytes[$cursor + 1]);
                $cursor += 2;
                if ($nLeftSize === 1) {
                    $nLeft = ord($bytes[$cursor]);
                    $cursor += 1;
                } else {
                    $nLeft = (ord($bytes[$cursor]) << 8) | ord($bytes[$cursor + 1]);
                    $cursor += 2;
                }
                for ($k = 0; $k <= $nLeft && $gid < $numGlyphs; $k++) {
                    $map[$gid++] = $first + $k;
                }
            }
            return new CffCharset($map, $format, substr($bytes, $offset, $cursor - $offset));
        }
        throw new PdfException("Unsupported CFF charset format {$format} for {$context}");
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
