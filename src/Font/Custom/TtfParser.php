<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Binary parser for TrueType fonts. Reads the offset table, table directory,
 * and the metric/encoding tables required for PDF embedding. Does not parse
 * the glyf/loca tables (Phase 3a embeds the entire TTF without subsetting).
 *
 * @internal
 */
final class TtfParser
{
    private const string SFNT_VERSION_TRUETYPE = "\x00\x01\x00\x00";
    private const string SFNT_VERSION_TRUE = 'true';

    public static function parse(string $bytes, string $contextLabel): ParsedTtf
    {
        self::validateMagic($bytes, $contextLabel);

        $tableDir = self::readTableDirectory($bytes, $contextLabel);

        $outlineFormat = self::detectOutlineFormat(
            $tableDir,
            substr($bytes, 0, 4),
            $contextLabel,
        );

        $head = self::readHeadTable($bytes, $tableDir, $contextLabel);
        $hhea = self::readHheaTable($bytes, $tableDir, $contextLabel);
        $maxp = self::readMaxpTable($bytes, $tableDir, $contextLabel);
        $os2 = self::readOs2Table($bytes, $tableDir, $contextLabel);
        $post = self::readPostTable($bytes, $tableDir, $contextLabel);
        $postScriptName = self::readNameTable($bytes, $tableDir, $contextLabel);
        $cmap = self::readCmapTable($bytes, $tableDir, $contextLabel);
        $widths = self::readHmtxTable($bytes, $tableDir, $hhea['numberOfHMetrics'], $maxp['numGlyphs'], $contextLabel);

        $flags = self::computeFlags($head, $os2, $post);

        return new ParsedTtf(
            bytes: $bytes,
            postScriptName: $postScriptName,
            unitsPerEm: $head['unitsPerEm'],
            ascent: $os2['typoAscender'],
            descent: $os2['typoDescender'],
            capHeight: $os2['capHeight'],
            xHeight: $os2['xHeight'],
            bbox: [$head['xMin'], $head['yMin'], $head['xMax'], $head['yMax']],
            italicAngle: $post['italicAngle'],
            weight: $os2['weightClass'],
            flags: $flags,
            cmap: $cmap,
            advanceWidthsByGid: $widths,
            outlineFormat: $outlineFormat,
        );
    }

    private static function validateMagic(string $bytes, string $ctx): void
    {
        if (strlen($bytes) < 12) {
            throw new PdfException("Invalid TTF file for {$ctx}: file too short");
        }
        $magic = substr($bytes, 0, 4);
        if ($magic === 'ttcf') {
            throw new PdfException("TrueType collection (.ttc) not supported, provide individual .ttf files: {$ctx}");
        }
        if (
            $magic !== self::SFNT_VERSION_TRUETYPE
            && $magic !== self::SFNT_VERSION_TRUE
            && $magic !== 'OTTO'
        ) {
            $hex = strtoupper(bin2hex($magic));
            throw new PdfException("Invalid TTF file for {$ctx}: unknown sfnt version 0x{$hex}");
        }
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private static function readTableDirectory(string $bytes, string $ctx): array
    {
        $unpacked = unpack('nnumTables', substr($bytes, 4, 2));
        if ($unpacked === false || !is_int($unpacked['numTables'])) {
            throw new PdfException("Invalid TTF file for {$ctx}: cannot read numTables");
        }
        $numTables = $unpacked['numTables'];
        if (strlen($bytes) < 12 + $numTables * 16) {
            throw new PdfException("Invalid TTF file for {$ctx}: table directory truncated");
        }
        $directory = [];
        for ($i = 0; $i < $numTables; $i++) {
            $entryOffset = 12 + $i * 16;
            $tag = substr($bytes, $entryOffset, 4);
            $rec = unpack('Nchecksum/Noffset/Nlength', substr($bytes, $entryOffset + 4, 12));
            if ($rec === false || !is_int($rec['offset']) || !is_int($rec['length'])) {
                throw new PdfException("Invalid TTF file for {$ctx}: corrupt table entry");
            }
            $directory[$tag] = [
                'offset' => $rec['offset'],
                'length' => $rec['length'],
            ];
        }
        return $directory;
    }

    /**
     * Determines the outline format from the table directory, independently
     * of the sfnt magic. glyf wins (a font carrying both is treated as
     * TrueType); CFF2 (variable) is rejected; 'CFF ' yields Cff; OTTO without
     * 'CFF ' and any font with neither outline table are rejected.
     *
     * @param array<string, array{offset: int, length: int}> $dir
     */
    private static function detectOutlineFormat(array $dir, string $magic, string $ctx): OutlineFormat
    {
        if (isset($dir['glyf'])) {
            return OutlineFormat::TrueType;
        }
        if (isset($dir['CFF2'])) {
            throw new PdfException("OpenType CFF2 (variable) fonts not supported for {$ctx}");
        }
        if (isset($dir['CFF '])) {
            return OutlineFormat::Cff;
        }
        if ($magic === 'OTTO') {
            throw new PdfException("Invalid OpenType font (OTTO without 'CFF ' table) for {$ctx}");
        }
        throw new PdfException("Unsupported font: no 'glyf' or 'CFF ' outline table for {$ctx}");
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{offset: int, length: int}
     */
    private static function requireTable(array $dir, string $tag, string $ctx): array
    {
        if (!isset($dir[$tag])) {
            throw new PdfException("Missing required TTF table '{$tag}' in {$ctx}");
        }
        return $dir[$tag];
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{unitsPerEm: int, xMin: int, yMin: int, xMax: int, yMax: int, macStyle: int}
     */
    private static function readHeadTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'head', $ctx);
        if ($entry['length'] < 54) {
            throw new PdfException("Invalid 'head' table in {$ctx}: too short");
        }
        $offset = $entry['offset'];
        return [
            'unitsPerEm' => self::readUint16($bytes, $offset + 18),
            'xMin' => self::readInt16($bytes, $offset + 36),
            'yMin' => self::readInt16($bytes, $offset + 38),
            'xMax' => self::readInt16($bytes, $offset + 40),
            'yMax' => self::readInt16($bytes, $offset + 42),
            'macStyle' => self::readUint16($bytes, $offset + 44),
        ];
    }

    private static function readUint16(string $bytes, int $offset): int
    {
        $unpacked = unpack('nv', substr($bytes, $offset, 2));
        if ($unpacked === false || !is_int($unpacked['v'])) {
            throw new PdfException("Cannot read uint16 at offset {$offset}");
        }
        return $unpacked['v'];
    }

    private static function readInt16(string $bytes, int $offset): int
    {
        $v = self::readUint16($bytes, $offset);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{ascender: int, descender: int, numberOfHMetrics: int}
     */
    private static function readHheaTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'hhea', $ctx);
        if ($entry['length'] < 36) {
            throw new PdfException("Invalid 'hhea' table in {$ctx}: too short");
        }
        return [
            'ascender' => self::readInt16($bytes, $entry['offset'] + 4),
            'descender' => self::readInt16($bytes, $entry['offset'] + 6),
            'numberOfHMetrics' => self::readUint16($bytes, $entry['offset'] + 34),
        ];
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{numGlyphs: int}
     */
    private static function readMaxpTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'maxp', $ctx);
        if ($entry['length'] < 6) {
            throw new PdfException("Invalid 'maxp' table in {$ctx}: too short");
        }
        return ['numGlyphs' => self::readUint16($bytes, $entry['offset'] + 4)];
    }

    /**
     * Reads the OS/2 table. Falls back gracefully on older versions where
     * sCapHeight and sxHeight are absent (added in v2): approximates them
     * from the typographic ascender (~70% / ~50%).
     *
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{typoAscender: int, typoDescender: int, capHeight: int, xHeight: int, weightClass: int, fsSelection: int, sFamilyClass: int}
     */
    private static function readOs2Table(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'OS/2', $ctx);
        if ($entry['length'] < 78) {
            throw new PdfException("Invalid 'OS/2' table in {$ctx}: too short");
        }
        $offset = $entry['offset'];
        $version = self::readUint16($bytes, $offset);
        $weightClass = self::readUint16($bytes, $offset + 4);
        $sFamilyClass = self::readInt16($bytes, $offset + 32);
        $fsSelection = self::readUint16($bytes, $offset + 62);
        $typoAscender = self::readInt16($bytes, $offset + 68);
        $typoDescender = self::readInt16($bytes, $offset + 70);

        if ($version >= 2 && $entry['length'] >= 90) {
            $xHeight = self::readInt16($bytes, $offset + 86);
            $capHeight = self::readInt16($bytes, $offset + 88);
        } else {
            $capHeight = (int) round($typoAscender * 0.7);
            $xHeight = (int) round($typoAscender * 0.5);
        }

        return [
            'typoAscender' => $typoAscender,
            'typoDescender' => $typoDescender,
            'capHeight' => $capHeight,
            'xHeight' => $xHeight,
            'weightClass' => $weightClass,
            'fsSelection' => $fsSelection,
            'sFamilyClass' => $sFamilyClass,
        ];
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{italicAngle: int, isFixedPitch: int}
     */
    private static function readPostTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'post', $ctx);
        if ($entry['length'] < 32) {
            throw new PdfException("Invalid 'post' table in {$ctx}: too short");
        }
        $italicAngle = self::readUint32($bytes, $entry['offset'] + 4);
        if ($italicAngle >= 0x80000000) {
            $italicAngle -= 0x100000000;
        }
        $isFixedPitch = self::readUint32($bytes, $entry['offset'] + 12);
        return ['italicAngle' => $italicAngle, 'isFixedPitch' => $isFixedPitch];
    }

    private static function readUint32(string $bytes, int $offset): int
    {
        $unpacked = unpack('Nv', substr($bytes, $offset, 4));
        if ($unpacked === false || !is_int($unpacked['v'])) {
            throw new PdfException("Cannot read uint32 at offset {$offset}");
        }
        return $unpacked['v'];
    }

    /**
     * Extracts the PostScriptName (name id 6). Prefers Platform 3 Encoding 1
     * (Microsoft Unicode BMP, decoded as UTF-16BE); falls back to Platform 1
     * Encoding 0 (Macintosh Roman, decoded as ASCII).
     *
     * @param array<string, array{offset: int, length: int}> $dir
     */
    private static function readNameTable(string $bytes, array $dir, string $ctx): string
    {
        $entry = self::requireTable($dir, 'name', $ctx);
        $offset = $entry['offset'];
        if ($entry['length'] < 6) {
            throw new PdfException("Invalid 'name' table in {$ctx}: too short");
        }
        $count = self::readUint16($bytes, $offset + 2);
        $stringOffset = self::readUint16($bytes, $offset + 4);

        /** @var array{0: int, 1: string, 2: int}|null $best */
        $best = null;
        for ($i = 0; $i < $count; $i++) {
            $rec = $offset + 6 + $i * 12;
            $platformId = self::readUint16($bytes, $rec);
            $encodingId = self::readUint16($bytes, $rec + 2);
            $nameId = self::readUint16($bytes, $rec + 6);
            $length = self::readUint16($bytes, $rec + 8);
            $strOffset = self::readUint16($bytes, $rec + 10);

            if ($nameId !== 6) {
                continue;
            }

            $raw = substr($bytes, $offset + $stringOffset + $strOffset, $length);

            $score = match (true) {
                $platformId === 3 && $encodingId === 1 => 2,
                $platformId === 1 && $encodingId === 0 => 1,
                default => 0,
            };
            if ($score === 0) {
                continue;
            }
            if ($best === null || $score > $best[0]) {
                $best = [$score, $raw, $platformId];
            }
        }

        if ($best === null) {
            throw new PdfException("No PostScriptName (name id 6) found in {$ctx}");
        }

        [, $raw, $platformId] = $best;
        $name = $platformId === 3
            ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE')
            : $raw;

        return self::sanitizePostScriptName($name);
    }

    private static function sanitizePostScriptName(string $raw): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._\-]/', '', $raw);
        if (!is_string($sanitized) || $sanitized === '') {
            $sanitized = 'CustomFont';
        }
        if (ctype_digit(substr($sanitized, 0, 1))) {
            $sanitized = 'F' . $sanitized;
        }
        return $sanitized;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array<int, int>
     */
    private static function readCmapTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'cmap', $ctx);
        $base = $entry['offset'];
        if ($entry['length'] < 4) {
            throw new PdfException("Invalid 'cmap' table in {$ctx}: too short");
        }
        $numTables = self::readUint16($bytes, $base + 2);

        $best12 = null;
        $best4 = null;
        for ($i = 0; $i < $numTables; $i++) {
            $rec = $base + 4 + $i * 8;
            $subOffset = self::readUint32($bytes, $rec + 4);
            $format = self::readUint16($bytes, $base + $subOffset);

            if ($format === 12 && $best12 === null) {
                $best12 = $base + $subOffset;
            } elseif ($format === 4 && $best4 === null) {
                $best4 = $base + $subOffset;
            }
        }

        if ($best12 !== null) {
            return self::parseCmapFormat12($bytes, $best12);
        }
        if ($best4 !== null) {
            return self::parseCmapFormat4($bytes, $best4);
        }

        throw new PdfException("No supported 'cmap' subtable (need format 4 or 12) in {$ctx}");
    }

    /**
     * @return array<int, int>
     */
    private static function parseCmapFormat4(string $bytes, int $offset): array
    {
        $segCountX2 = self::readUint16($bytes, $offset + 6);
        $segCount = (int) ($segCountX2 / 2);

        $endCodes = [];
        $startCodes = [];
        $idDeltas = [];
        $idRangeOffsets = [];

        $cursor = $offset + 14;
        for ($i = 0; $i < $segCount; $i++) {
            $endCodes[] = self::readUint16($bytes, $cursor + $i * 2);
        }
        $cursor += $segCountX2 + 2;
        for ($i = 0; $i < $segCount; $i++) {
            $startCodes[] = self::readUint16($bytes, $cursor + $i * 2);
        }
        $cursor += $segCountX2;
        for ($i = 0; $i < $segCount; $i++) {
            $delta = self::readUint16($bytes, $cursor + $i * 2);
            if ($delta >= 0x8000) {
                $delta -= 0x10000;
            }
            $idDeltas[] = $delta;
        }
        $cursor += $segCountX2;
        $idRangeOffsetsBase = $cursor;
        for ($i = 0; $i < $segCount; $i++) {
            $idRangeOffsets[] = self::readUint16($bytes, $cursor + $i * 2);
        }

        $result = [];
        for ($i = 0; $i < $segCount; $i++) {
            $start = $startCodes[$i];
            $end = $endCodes[$i];
            if ($start === 0xFFFF && $end === 0xFFFF) {
                continue;
            }
            $delta = $idDeltas[$i];
            $rangeOffset = $idRangeOffsets[$i];
            for ($cp = $start; $cp <= $end; $cp++) {
                if ($rangeOffset === 0) {
                    $gid = ($cp + $delta) & 0xFFFF;
                } else {
                    $glyphAddr = $idRangeOffsetsBase + $i * 2 + ($cp - $start) * 2 + $rangeOffset;
                    $glyph = self::readUint16($bytes, $glyphAddr);
                    $gid = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                }
                if ($gid !== 0) {
                    $result[$cp] = $gid;
                }
            }
        }
        return $result;
    }

    /**
     * @return array<int, int>
     */
    private static function parseCmapFormat12(string $bytes, int $offset): array
    {
        $numGroups = self::readUint32($bytes, $offset + 12);
        $result = [];
        for ($i = 0; $i < $numGroups; $i++) {
            $g = $offset + 16 + $i * 12;
            $start = self::readUint32($bytes, $g);
            $end = self::readUint32($bytes, $g + 4);
            $gidStart = self::readUint32($bytes, $g + 8);
            for ($cp = $start; $cp <= $end; $cp++) {
                $result[$cp] = $gidStart + ($cp - $start);
            }
        }
        return $result;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array<int, int>
     */
    private static function readHmtxTable(
        string $bytes,
        array $dir,
        int $numberOfHMetrics,
        int $numGlyphs,
        string $ctx,
    ): array {
        $entry = self::requireTable($dir, 'hmtx', $ctx);
        $offset = $entry['offset'];
        $widths = [];
        $lastAdvance = 0;
        for ($g = 0; $g < $numberOfHMetrics; $g++) {
            $w = self::readUint16($bytes, $offset + $g * 4);
            $widths[$g] = $w;
            $lastAdvance = $w;
        }
        for ($g = $numberOfHMetrics; $g < $numGlyphs; $g++) {
            $widths[$g] = $lastAdvance;
        }
        return $widths;
    }

    /**
     * Computes the FontDescriptor /Flags bitmask per PDF spec 9.8.2 Table 123.
     * For Latin TTFs we always set Nonsymbolic; Symbol/dingbat fonts are out of
     * Phase 3a scope, so we never emit Symbolic.
     *
     * @param array{macStyle: int} $head
     * @param array{fsSelection: int, sFamilyClass: int} $os2
     * @param array{isFixedPitch: int} $post
     */
    private static function computeFlags(array $head, array $os2, array $post): int
    {
        $flags = 0;
        if ($post['isFixedPitch'] !== 0) {
            $flags |= 0x01;
        }
        $familyClass = ($os2['sFamilyClass'] >> 8) & 0xFF;
        if ($familyClass >= 1 && $familyClass <= 7) {
            $flags |= 0x02;
        }
        if ($familyClass === 10) {
            $flags |= 0x08;
        }
        $flags |= 0x20;
        if (($os2['fsSelection'] & 0x01) !== 0 || ($head['macStyle'] & 0x02) !== 0) {
            $flags |= 0x40;
        }
        return $flags;
    }
}
