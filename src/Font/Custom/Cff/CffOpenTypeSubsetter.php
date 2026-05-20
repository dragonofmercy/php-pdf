<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\SfntReader;

/**
 * Pipeline facade: takes the raw bytes of an OTF/CFF font and a closure
 * (set of GIDs to keep), returns rebuilt OTF bytes whose 'CFF ' table has
 * been replaced by the subsetted CFF. The SFNT table directory is rewritten
 * with new offsets/lengths/checksums and the head.checkSumAdjustment field
 * is recomputed.
 *
 * @internal
 */
final class CffOpenTypeSubsetter
{
    private const int HEAD_CHECKSUM_ADJUSTMENT_OFFSET = 8;
    private const int CHECKSUM_MAGIC = 0xB1B0AFBA;

    /**
     * @param array<int, true> $closure  GIDs to keep (must include 0)
     */
    public function subset(string $otfBytes, array $closure, string $context): string
    {
        $cffBytes = SfntReader::extractTable($otfBytes, 'CFF ', $context);
        $parsed = (new CffReader())->read($cffBytes, $context);
        $reduced = (new CffSubsetter())->subset($parsed, $closure, $context);
        $newCff = (new CffWriter())->write($reduced);
        return $this->rebuildSfnt($otfBytes, $newCff, $context);
    }

    private function rebuildSfnt(string $otfBytes, string $newCff, string $context): string
    {
        $dir = SfntReader::directory($otfBytes, $context);
        $numTables = count($dir);

        // Search range / entrySelector / rangeShift per sfnt spec.
        $pow = 1;
        $sel = 0;
        while ($pow * 2 <= $numTables) {
            $pow *= 2;
            $sel++;
        }
        $offsetTable = substr($otfBytes, 0, 4)
            . pack('n', $numTables)
            . pack('n', $pow * 16)
            . pack('n', $sel)
            . pack('n', $numTables * 16 - $pow * 16);

        // Re-assemble tables in ascending tag order.
        $tables = [];
        foreach ($dir as $tag => $entry) {
            if ($tag === 'CFF ') {
                $tables[$tag] = $newCff;
            } elseif ($tag === 'head') {
                $head = substr($otfBytes, $entry['offset'], $entry['length']);
                $head = substr_replace($head, "\x00\x00\x00\x00", self::HEAD_CHECKSUM_ADJUSTMENT_OFFSET, 4);
                $tables[$tag] = $head;
            } else {
                $tables[$tag] = substr($otfBytes, $entry['offset'], $entry['length']);
            }
        }
        ksort($tables);

        $headerSize = 12 + $numTables * 16;
        $running = $headerSize;
        $directory = '';
        $body = '';
        $headOffsetInFile = null;
        foreach ($tables as $tag => $data) {
            $pad = (4 - strlen($data) % 4) % 4;
            $padded = $data . str_repeat("\x00", $pad);
            if ($tag === 'head') {
                $headOffsetInFile = $running;
            }
            $directory .= $tag
                . pack('N', $this->checksum($padded))
                . pack('N', $running)
                . pack('N', strlen($data));
            $body .= $padded;
            $running += strlen($padded);
        }

        $file = $offsetTable . $directory . $body;

        if ($headOffsetInFile === null) {
            throw new PdfException("OTF rebuild missing 'head' table for {$context}");
        }
        $adjustment = (self::CHECKSUM_MAGIC - $this->checksum($file)) & 0xFFFFFFFF;
        return substr_replace(
            $file,
            pack('N', $adjustment),
            $headOffsetInFile + self::HEAD_CHECKSUM_ADJUSTMENT_OFFSET,
            4,
        );
    }

    private function checksum(string $bytes): int
    {
        $pad = (4 - strlen($bytes) % 4) % 4;
        $words = unpack('N*', $bytes . str_repeat("\x00", $pad));
        if ($words === false) {
            throw new PdfException('CFF rebuild: cannot unpack table bytes for checksum');
        }
        $sum = 0;
        foreach ($words as $w) {
            if (!is_int($w)) {
                throw new PdfException('CFF rebuild: non-int word from unpack');
            }
            $sum = ($sum + $w) & 0xFFFFFFFF;
        }
        return $sum;
    }
}
