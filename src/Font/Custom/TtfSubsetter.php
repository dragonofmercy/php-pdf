<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Produces a GID-preserving subset of a TrueType font: glyf/loca are rebuilt
 * (closure glyphs copied verbatim, others emitted empty), all other kept
 * tables are copied byte-for-byte, every other table is dropped. The table
 * directory, per-table checksums and head.checkSumAdjustment are recomputed.
 * loca is always emitted in long format (head.indexToLocFormat patched to 1).
 *
 * Output is byte-deterministic for a given (bytes, closure): fixed table
 * order (ascending tag), fixed 4-byte zero padding, ascending GID iteration.
 *
 * @internal
 */
final class TtfSubsetter
{
    private const int HEAD_CHECKSUM_ADJUSTMENT_OFFSET = 8;
    private const int HEAD_INDEX_TO_LOC_FORMAT_OFFSET = 50;

    /** @var list<string> */
    private const array KEEP = ['OS/2', 'cmap', 'hhea', 'hmtx', 'maxp', 'name', 'post'];

    /**
     * @param array<int, true> $closure
     */
    public static function subset(string $ttf, array $closure, string $context): string
    {
        $dir = SfntReader::directory($ttf, $context);
        foreach (['glyf', 'loca', 'head', 'maxp'] as $req) {
            if (!isset($dir[$req])) {
                throw new PdfException("Cannot subset font '{$context}': missing required '{$req}' table");
            }
        }

        $indexToLocFormat = SfntReader::u16($ttf, $dir['head']['offset'] + self::HEAD_INDEX_TO_LOC_FORMAT_OFFSET);
        $numGlyphs = SfntReader::u16($ttf, $dir['maxp']['offset'] + 4);
        $origLoca = SfntReader::loca($ttf, $dir['loca']['offset'], $indexToLocFormat, $numGlyphs);
        $glyfBase = $dir['glyf']['offset'];

        $newGlyf = '';
        $newLoca = [];
        $pos = 0;
        for ($gid = 0; $gid < $numGlyphs; $gid++) {
            $newLoca[] = $pos;
            $start = $origLoca[$gid];
            $end = $origLoca[$gid + 1];
            if (isset($closure[$gid]) && $end > $start) {
                $glyph = substr($ttf, $glyfBase + $start, $end - $start);
            } else {
                $glyph = '';
            }
            $pad = (4 - strlen($glyph) % 4) % 4;
            $newGlyf .= $glyph . str_repeat("\x00", $pad);
            $pos += strlen($glyph) + $pad;
        }
        $newLoca[] = $pos;

        $locaBytes = '';
        foreach ($newLoca as $off) {
            $locaBytes .= pack('N', $off);
        }

        $head = substr($ttf, $dir['head']['offset'], $dir['head']['length']);
        $head = substr_replace($head, pack('n', 1), self::HEAD_INDEX_TO_LOC_FORMAT_OFFSET, 2);
        $head = substr_replace($head, "\x00\x00\x00\x00", self::HEAD_CHECKSUM_ADJUSTMENT_OFFSET, 4);

        $tables = ['head' => $head, 'glyf' => $newGlyf, 'loca' => $locaBytes];
        foreach (self::KEEP as $tag) {
            if (isset($dir[$tag])) {
                $tables[$tag] = substr($ttf, $dir[$tag]['offset'], $dir[$tag]['length']);
            }
        }
        ksort($tables);

        $numTables = count($tables);
        $pow = 1;
        $sel = 0;
        while ($pow * 2 <= $numTables) {
            $pow *= 2;
            $sel++;
        }
        $offsetTable = "\x00\x01\x00\x00"
            . pack('n', $numTables)
            . pack('n', $pow * 16)
            . pack('n', $sel)
            . pack('n', $numTables * 16 - $pow * 16);

        $headerSize = 12 + $numTables * 16;
        $running = $headerSize;
        $directory = '';
        $body = '';
        foreach ($tables as $tag => $data) {
            $pad = (4 - strlen($data) % 4) % 4;
            $padded = $data . str_repeat("\x00", $pad);
            $directory .= $tag
                . pack('N', self::checksum($padded))
                . pack('N', $running)
                . pack('N', strlen($data));
            $body .= $padded;
            $running += strlen($padded);
        }

        $file = $offsetTable . $directory . $body;

        $tagIndex = 0;
        foreach (array_keys($tables) as $tag) {
            if ($tag === 'head') {
                break;
            }
            $tagIndex++;
        }
        $headOffsetInFile = SfntReader::u32($file, 12 + $tagIndex * 16 + 8);

        $adjustment = (0xB1B0AFBA - self::checksum($file)) & 0xFFFFFFFF;
        return substr_replace(
            $file,
            pack('N', $adjustment),
            $headOffsetInFile + self::HEAD_CHECKSUM_ADJUSTMENT_OFFSET,
            4,
        );
    }

    private static function checksum(string $bytes): int
    {
        $pad = (4 - strlen($bytes) % 4) % 4;
        $words = unpack('N*', $bytes . str_repeat("\x00", $pad));
        if ($words === false) {
            throw new PdfException('Checksum failed: cannot unpack table bytes');
        }
        $sum = 0;
        foreach ($words as $w) {
            $sum += is_int($w) ? $w : 0;
        }
        return $sum & 0xFFFFFFFF;
    }
}
