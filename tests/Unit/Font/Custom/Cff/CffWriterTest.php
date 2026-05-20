<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffCharStrings;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffReader;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffTopDictData;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffWriter;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\ParsedCff;
use PHPUnit\Framework\TestCase;

final class CffWriterTest extends TestCase
{
    public function testRoundTripPreservesStructure(): void
    {
        $bytes = $this->minimalNameKeyedCff('Plex', numGlyphs: 4);
        $reader = new CffReader();
        $parsed = $reader->read($bytes, 'Plex');
        $written = (new CffWriter())->write($parsed);
        $reparsed = $reader->read($written, 'Plex-rt');
        self::assertSame('Plex', $reparsed->nameIndexEntry);
        self::assertSame(4, $reparsed->topDictData[0]->charStrings->numGlyphs);
        self::assertSame(
            $parsed->topDictData[0]->charStrings->glyphs,
            $reparsed->topDictData[0]->charStrings->glyphs,
        );
        self::assertSame(
            $parsed->topDictData[0]->charset->gidToNameOrCid,
            $reparsed->topDictData[0]->charset->gidToNameOrCid,
        );
    }

    public function testWriteIsDeterministic(): void
    {
        $bytes = $this->minimalNameKeyedCff('Plex', numGlyphs: 4);
        $parsed = (new CffReader())->read($bytes, 'Plex');
        $a = (new CffWriter())->write($parsed);
        $b = (new CffWriter())->write($parsed);
        self::assertSame($a, $b);
    }

    public function testCharStringsIndexHasNumGlyphsEntries(): void
    {
        $bytes = $this->minimalNameKeyedCff('Plex', numGlyphs: 4);
        $parsed = (new CffReader())->read($bytes, 'Plex');
        $td = $parsed->topDictData[0];
        $reducedCs = new CffCharStrings(
            [0 => $td->charStrings->glyphs[0], 2 => $td->charStrings->glyphs[2]],
            $td->charStrings->numGlyphs,
        );
        $newTd = new CffTopDictData(
            $td->charset, $td->encoding, $reducedCs, $td->namePrivate, $td->cidKeyed,
        );
        $reduced = new ParsedCff(
            $parsed->header, $parsed->nameIndexEntry, $parsed->topDicts,
            $parsed->stringIndex, $parsed->gsubrsIndex, [$newTd],
        );
        $written = (new CffWriter())->write($reduced);
        $reparsed = (new CffReader())->read($written, 'rt');
        self::assertSame(4, $reparsed->topDictData[0]->charStrings->numGlyphs);
        self::assertSame('', $reparsed->topDictData[0]->charStrings->glyphs[1]);
        self::assertSame('', $reparsed->topDictData[0]->charStrings->glyphs[3]);
        self::assertSame(
            $parsed->topDictData[0]->charStrings->glyphs[0],
            $reparsed->topDictData[0]->charStrings->glyphs[0],
        );
        self::assertSame(
            $parsed->topDictData[0]->charStrings->glyphs[2],
            $reparsed->topDictData[0]->charStrings->glyphs[2],
        );
    }

    public function testWrittenStreamShrinksWithSparseClosure(): void
    {
        $bytes = $this->minimalNameKeyedCff('Plex', numGlyphs: 4);
        $parsed = (new CffReader())->read($bytes, 'Plex');
        $td = $parsed->topDictData[0];
        $kept = new CffCharStrings(
            [0 => $td->charStrings->glyphs[0]],
            $td->charStrings->numGlyphs,
        );
        $newTd = new CffTopDictData(
            $td->charset, $td->encoding, $kept, $td->namePrivate, $td->cidKeyed,
        );
        $reduced = new ParsedCff(
            $parsed->header, $parsed->nameIndexEntry, $parsed->topDicts,
            $parsed->stringIndex, $parsed->gsubrsIndex, [$newTd],
        );
        $full = (new CffWriter())->write($parsed);
        $sparse = (new CffWriter())->write($reduced);
        self::assertLessThan(strlen($full), strlen($sparse));
    }

    private function minimalNameKeyedCff(string $name, int $numGlyphs): string
    {
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::idx([$name]);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        $cs = self::idx(array_map(static fn (int $i): string => "\x8b" . "\x0e", range(0, $numGlyphs - 1)));
        $charset = "\x00";
        for ($g = 1; $g < $numGlyphs; $g++) {
            $charset .= pack('n', $g);
        }
        $build = static fn (int $cset, int $csOff): string =>
            "\x1d" . pack('N', $cset) . "\x0f"
            . "\x1d" . pack('N', $csOff) . "\x11";
        $topDictIndex = self::idx([$build(0, 0)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $csetOff = strlen($preamble);
        $csOff = $csetOff + strlen($charset);
        $topDictIndex = self::idx([$build($csetOff, $csOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        return $preamble . $charset . $cs;
    }

    /** @param list<string> $entries */
    private static function idx(array $entries): string
    {
        $count = count($entries);
        if ($count === 0) {
            return "\x00\x00";
        }
        $data = implode('', $entries);
        $totalLen = strlen($data);
        $offSize = ($totalLen + 1 < 0x100) ? 1 : (($totalLen + 1 < 0x10000) ? 2 : 4);
        $offsets = '';
        $cursor = 1;
        $pack = static fn (int $v): string => $offSize === 1 ? chr($v & 0xFF) : ($offSize === 2 ? pack('n', $v) : pack('N', $v));
        $offsets .= $pack($cursor);
        foreach ($entries as $entry) {
            $cursor += strlen($entry);
            $offsets .= $pack($cursor);
        }
        return pack('n', $count) . chr($offSize) . $offsets . $data;
    }
}
