<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffReader;
use PHPUnit\Framework\TestCase;

final class CffReaderTest extends TestCase
{
    private function minimalNameKeyedHeaderOnly(): string
    {
        // Top DICT extra payload: operand 1 (0x8C = 1+139) then operator 0x00 (version, SID 1).
        // The wrapper appends charset + CharStrings offsets and the matching tables.
        return $this->wrapTopDict("\x8C\x00", 'ABC');
    }

    /** @param list<string> $entries */
    public static function buildIndex(array $entries): string
    {
        $count = count($entries);
        if ($count === 0) {
            return "\x00\x00";
        }
        $data = implode('', $entries);
        $totalLen = strlen($data);
        $offSize = self::minOffSize($totalLen + 1);
        $offsets = '';
        $cursor = 1;
        $offsets .= self::packOff($cursor, $offSize);
        foreach ($entries as $entry) {
            $cursor += strlen($entry);
            $offsets .= self::packOff($cursor, $offSize);
        }
        return pack('n', $count) . chr($offSize & 0xFF) . $offsets . $data;
    }

    private static function minOffSize(int $maxOff): int
    {
        if ($maxOff < 0x100) {
            return 1;
        }
        if ($maxOff < 0x10000) {
            return 2;
        }
        if ($maxOff < 0x1000000) {
            return 3;
        }
        return 4;
    }

    private static function packOff(int $v, int $size): string
    {
        return match ($size) {
            1 => chr($v & 0xFF),
            2 => pack('n', $v),
            3 => substr(pack('N', $v), 1),
            4 => pack('N', $v),
            default => throw new \LogicException('bad offSize'),
        };
    }

    public static function encodeInt(int $v): string
    {
        if ($v >= -107 && $v <= 107) {
            return chr($v + 139);
        }
        if ($v >= 108 && $v <= 1131) {
            $v -= 108;
            return chr((intdiv($v, 256) + 247) & 0xFF) . chr(($v % 256) & 0xFF);
        }
        if ($v >= -1131 && $v <= -108) {
            $v = -$v - 108;
            return chr((intdiv($v, 256) + 251) & 0xFF) . chr(($v % 256) & 0xFF);
        }
        if ($v >= -32768 && $v <= 32767) {
            return "\x1c" . pack('n', $v & 0xFFFF);
        }
        return "\x1d" . pack('N', $v & 0xFFFFFFFF);
    }

    /**
     * Wrap a Top DICT body in a complete CFF stream. The body provided by the
     * caller carries whatever extra operators the test wants to exercise;
     * this helper appends the mandatory long-form charset + CharStrings
     * offset operands so the strict reader is satisfied, then emits a 1-glyph
     * charset (format 0) + CharStrings INDEX (single endchar) at the end.
     */
    private function wrapTopDict(string $topDictBytes, string $name): string
    {
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex([$name]);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";

        // 1-glyph CharStrings INDEX (single endchar byte) and format-0 charset.
        $cs = self::buildIndex(["\x0e"]);
        $charset = "\x00"; // format 0, no SID entries because numGlyphs - 1 = 0.

        $buildBody = static fn (int $charsetOff, int $csOff): string =>
            $topDictBytes
            . "\x1d" . pack('N', $charsetOff) . "\x0f"
            . "\x1d" . pack('N', $csOff) . "\x11";

        $topDictIndex = self::buildIndex([$buildBody(0, 0)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $charsetOff = strlen($preamble);
        $csOff = $charsetOff + strlen($charset);
        $topDictIndex = self::buildIndex([$buildBody($charsetOff, $csOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        return $preamble . $charset . $cs;
    }

    public function testHeaderIsParsed(): void
    {
        $cff = (new CffReader())->read($this->minimalNameKeyedHeaderOnly(), 'Synthetic');
        self::assertSame(1, $cff->header->major);
        self::assertSame(0, $cff->header->minor);
        self::assertSame(4, $cff->header->hdrSize);
        self::assertSame(1, $cff->header->offSize);
    }

    public function testNameIndexEntryIsParsed(): void
    {
        $cff = (new CffReader())->read($this->minimalNameKeyedHeaderOnly(), 'Synthetic');
        self::assertSame('ABC', $cff->nameIndexEntry);
    }

    public function testTopDictIsDeserialisedWithOperatorNames(): void
    {
        $cff = (new CffReader())->read($this->minimalNameKeyedHeaderOnly(), 'Synthetic');
        self::assertCount(1, $cff->topDicts);
        self::assertSame(1, $cff->topDicts[0]['version']);
    }

    public function testEmptyIndexesProduceEmptyLists(): void
    {
        $cff = (new CffReader())->read($this->minimalNameKeyedHeaderOnly(), 'Synthetic');
        self::assertSame([], $cff->stringIndex);
        self::assertSame([], $cff->gsubrsIndex);
    }

    public function testIntegerOperandBandsAreDecoded(): void
    {
        $bbox = self::encodeInt(-100) . self::encodeInt(-200) . self::encodeInt(1100) . self::encodeInt(800);
        $topDictBytes = $bbox . "\x05"; // operator 5 = FontBBox
        $cff = (new CffReader())->read($this->wrapTopDict($topDictBytes, 'X'), 'Synthetic');
        self::assertSame([-100, -200, 1100, 800], $cff->topDicts[0]['FontBBox']);
    }

    public function testRejectsNameIndexWithZeroEntries(): void
    {
        $bytes = "\x01\x00\x04\x01" . "\x00\x00" . self::buildIndex(["\x8C\x00"]) . "\x00\x00\x00\x00";
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('CFF Name INDEX must contain exactly 1 entry, got 0');
        (new CffReader())->read($bytes, 'X');
    }

    public function testRejectsNameIndexWithMultipleEntries(): void
    {
        $bytes = "\x01\x00\x04\x01" . self::buildIndex(['A', 'B']) . self::buildIndex(["\x8C\x00"]) . "\x00\x00\x00\x00";
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('CFF Name INDEX must contain exactly 1 entry, got 2');
        (new CffReader())->read($bytes, 'X');
    }

    public function testRejectsUnknownTopDictOperator(): void
    {
        // operand 1 then unknown 2-byte escape 0x0c 0xFE
        $bytes = $this->wrapTopDict("\x8C\x0c\xFE", 'X');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unsupported CFF operator');
        (new CffReader())->read($bytes, 'X');
    }

    public function testRejectsInvalidIndexOffSize(): void
    {
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex(['A']);
        // count=1, offSize=5 (invalid), 2 zero offsets of 5 bytes each, then dummy DICT byte
        $badIndex = pack('n', 1) . "\x05" . str_repeat("\x00", 10) . "\x8C\x00";
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid CFF INDEX offSize 5');
        (new CffReader())->read($header . $nameIndex . $badIndex, 'X');
    }

    private function buildMinimalNameKeyedCff(
        string $name,
        int $numGlyphs,
        int $charsetFormat,
    ): string {
        // CharStrings INDEX: $numGlyphs entries, each 1 byte (endchar 0x0e)
        $cs = self::buildIndex(array_fill(0, $numGlyphs, "\x0e"));

        // Charset (size depends on format): trivial mapping GID i -> SID i (i in 1..numGlyphs-1)
        if ($charsetFormat === 0) {
            $charset = "\x00";
            for ($i = 1; $i < $numGlyphs; $i++) {
                $charset .= pack('n', $i);
            }
        } elseif ($charsetFormat === 1) {
            // single range1: first SID = 1, nLeft = numGlyphs - 2
            $charset = "\x01" . pack('n', 1) . chr(($numGlyphs - 2) & 0xFF);
        } else {
            // format 2: single range2
            $charset = "\x02" . pack('n', 1) . pack('n', $numGlyphs - 2);
        }

        // Order body: header | NameINDEX | TopDictINDEX | StringINDEX | GSubrsINDEX | charset | CharStrings
        // The Top DICT carries offsets for charset and CharStrings.
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex([$name]);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";

        // Compute placeholder offsets: build pass 1 with both offsets as 5-byte long form (29 + uint32 = 5 bytes each).
        $buildTopDict = static function (int $charsetOff, int $charStringsOff): string {
            return "\x1d" . pack('N', $charsetOff) . "\x0f"
                . "\x1d" . pack('N', $charStringsOff) . "\x11";
        };
        $topDictBytes = $buildTopDict(0, 0);
        $topDictIndex = self::buildIndex([$topDictBytes]);

        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $charsetOff = strlen($preamble);
        $charStringsOff = $charsetOff + strlen($charset);
        $topDictBytes = $buildTopDict($charsetOff, $charStringsOff);
        $topDictIndex = self::buildIndex([$topDictBytes]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        // Sanity: same length (long form is fixed 5 bytes per offset operand) so offsets remain valid
        return $preamble . $charset . $cs;
    }

    public function testCharsetFormat0IsDecoded(): void
    {
        $bytes = $this->buildMinimalNameKeyedCff('Plex', numGlyphs: 4, charsetFormat: 0);
        $cff = (new CffReader())->read($bytes, 'Plex');
        $td = $cff->topDictData[0];
        self::assertSame(0, $td->charset->format);
        self::assertSame([0 => 0, 1 => 1, 2 => 2, 3 => 3], $td->charset->gidToNameOrCid);
        self::assertNull($td->cidKeyed);
    }

    public function testCharsetFormat1IsDecoded(): void
    {
        $bytes = $this->buildMinimalNameKeyedCff('Plex', numGlyphs: 4, charsetFormat: 1);
        $cff = (new CffReader())->read($bytes, 'Plex');
        $td = $cff->topDictData[0];
        self::assertSame(1, $td->charset->format);
        // range: first SID = 1, nLeft = 2 -> GID1=1, GID2=2, GID3=3
        self::assertSame([0 => 0, 1 => 1, 2 => 2, 3 => 3], $td->charset->gidToNameOrCid);
    }

    public function testCharsetFormat2IsDecoded(): void
    {
        $bytes = $this->buildMinimalNameKeyedCff('Plex', numGlyphs: 4, charsetFormat: 2);
        $cff = (new CffReader())->read($bytes, 'Plex');
        $td = $cff->topDictData[0];
        self::assertSame(2, $td->charset->format);
        self::assertSame([0 => 0, 1 => 1, 2 => 2, 3 => 3], $td->charset->gidToNameOrCid);
    }

    public function testRejectsUnknownCharsetFormat(): void
    {
        // Build a CFF with a hand-rolled charset whose format byte is 99
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex(['Plex']);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        $charsetBad = "\x63\x00\x01"; // format 99 then 2 junk bytes
        $cs = self::buildIndex(array_fill(0, 4, "\x0e"));
        $build = static fn (int $a, int $b): string => "\x1d" . pack('N', $a) . "\x0f" . "\x1d" . pack('N', $b) . "\x11";
        $topDictIndex = self::buildIndex([$build(0, 0)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $charsetOff = strlen($preamble);
        $charStringsOff = $charsetOff + strlen($charsetBad);
        $topDictIndex = self::buildIndex([$build($charsetOff, $charStringsOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $bad = $preamble . $charsetBad . $cs;

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unsupported CFF charset format 99');
        (new CffReader())->read($bad, 'Plex');
    }

    public function testCharStringsEntriesAreDecoded(): void
    {
        $bytes = $this->buildMinimalNameKeyedCff('Plex', numGlyphs: 4, charsetFormat: 0);
        // Builder fills every entry with single byte 0x0e (endchar).
        $cff = (new CffReader())->read($bytes, 'Plex');
        $cs = $cff->topDictData[0]->charStrings;
        self::assertSame(4, $cs->numGlyphs);
        self::assertCount(4, $cs->glyphs);
        self::assertSame("\x0e", $cs->glyphs[0]);
        self::assertSame("\x0e", $cs->glyphs[3]);
    }

    public function testRejectsEncodingTruncatedCountByte(): void
    {
        // Build a CFF where the Top DICT carries an Encoding operator (0x10) whose
        // offset points at the very last byte of the stream. The format byte is
        // therefore readable but the count byte that immediately follows is past
        // strlen($bytes), so readEncoding must raise rather than silently treat
        // the missing byte as a zero count (PHP's ord() on an OOB index returns 0).
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex(['Plex']);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        $cs = self::buildIndex(["\x0e"]);
        $charset = "\x00"; // format 0, 1-glyph (no SID entries needed)

        // The Encoding payload is a single byte 0x00 (format 0, no supplemental, but the
        // count byte that should follow is absent). The Encoding operand is the absolute
        // offset where that lone byte sits, which is the last byte of the stream.
        $encodingPayload = "\x00";

        $buildTopDict = static fn (int $charsetOff, int $csOff, int $encOff): string =>
            "\x1d" . pack('N', $charsetOff) . "\x0f"
            . "\x1d" . pack('N', $csOff) . "\x11"
            . "\x1d" . pack('N', $encOff) . "\x10";

        $topDictIndex = self::buildIndex([$buildTopDict(0, 0, 0)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $charsetOff = strlen($preamble);
        $csOff = $charsetOff + strlen($charset);
        $encOff = $csOff + strlen($cs);
        $topDictIndex = self::buildIndex([$buildTopDict($charsetOff, $csOff, $encOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $bytes = $preamble . $charset . $cs . $encodingPayload;

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('CFF encoding truncated count byte');
        (new CffReader())->read($bytes, 'Plex');
    }

    public function testNameKeyedPrivateDictAndSubrsAreDecoded(): void
    {
        // Build a CFF that adds a Private DICT (with one operator BlueValues + Subrs offset)
        // and a local Subrs INDEX (1 entry of 1 byte). Layout:
        //   header | NameINDEX | TopDictINDEX | StringINDEX | GSubrsINDEX | charset | CharStrings | PrivateDICT | LocalSubrs
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex(['Plex']);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        $cs = self::buildIndex(array_fill(0, 4, "\x0e"));
        $charset = "\x00" . pack('n', 1) . pack('n', 2) . pack('n', 3);

        $build = static fn (int $csetOff, int $csOff, int $privSize, int $privOff): string =>
            "\x1d" . pack('N', $csetOff) . "\x0f"
            . "\x1d" . pack('N', $csOff) . "\x11"
            . self::encodeInt($privSize) . "\x1d" . pack('N', $privOff) . "\x12";

        // pass 1 placeholder offsets
        $topDictIndex = self::buildIndex([$build(0, 0, 0, 0)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $csetOff = strlen($preamble);
        $csOff = $csetOff + strlen($charset);
        $privOff = $csOff + strlen($cs);
        // Private DICT body: BlueValues = delta-encoded [0, 0] (one operand, then operator 6),
        // Subrs operator (0x13) with operand = relative offset where local Subrs INDEX starts.
        // We place LocalSubrs right after the Private DICT body; offset is its position
        // relative to $privOff.
        $localSubrs = self::buildIndex(["\x0b"]);
        // Build the private body without Subrs offset first to measure its length, then re-emit.
        $build2 = static function (int $subrsRel): string {
            // BlueValues operands [0]: 0 -> single byte 139
            return "\x8b" . "\x06" . self::encodeInt($subrsRel) . "\x13";
        };
        // pass 1 placeholder for Subrs rel offset
        $privBody1 = $build2(0);
        $privSize = strlen($privBody1);
        $subrsRel = $privSize; // local Subrs INDEX immediately after Private body
        $privBody = $build2($subrsRel);

        // assemble pass 2 with final offsets
        $topDictIndex = self::buildIndex([$build($csetOff, $csOff, $privSize, $privOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $csetOff = strlen($preamble);
        $csOff = $csetOff + strlen($charset);
        $privOff = $csOff + strlen($cs);
        $topDictIndex = self::buildIndex([$build($csetOff, $csOff, $privSize, $privOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $bytes = $preamble . $charset . $cs . $privBody . $localSubrs;

        $cff = (new CffReader())->read($bytes, 'Plex');
        $td = $cff->topDictData[0];
        self::assertNotNull($td->namePrivate);
        self::assertArrayHasKey('BlueValues', $td->namePrivate->privateDict);
        self::assertSame([0], (array) $td->namePrivate->privateDict['BlueValues']);
        self::assertSame(["\x0b"], $td->namePrivate->localSubrs);
    }

    public function testPrivateWithoutSubrsHasEmptyLocalSubrs(): void
    {
        // Same as above but no Subrs operator in the Private DICT.
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex(['Plex']);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        $cs = self::buildIndex(array_fill(0, 4, "\x0e"));
        $charset = "\x00" . pack('n', 1) . pack('n', 2) . pack('n', 3);

        $privBody = "\x8b" . "\x06"; // BlueValues = [0], no Subrs
        $privSize = strlen($privBody);
        $build = static fn (int $a, int $b, int $sz, int $po): string =>
            "\x1d" . pack('N', $a) . "\x0f"
            . "\x1d" . pack('N', $b) . "\x11"
            . self::encodeInt($sz) . "\x1d" . pack('N', $po) . "\x12";
        $topDictIndex = self::buildIndex([$build(0, 0, $privSize, 0)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $csetOff = strlen($preamble);
        $csOff = $csetOff + strlen($charset);
        $privOff = $csOff + strlen($cs);
        $topDictIndex = self::buildIndex([$build($csetOff, $csOff, $privSize, $privOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $bytes = $preamble . $charset . $cs . $privBody;

        $cff = (new CffReader())->read($bytes, 'Plex');
        self::assertNotNull($cff->topDictData[0]->namePrivate);
        self::assertSame([], $cff->topDictData[0]->namePrivate->localSubrs);
    }

    public function testCidKeyedDiscriminationByRosOperator(): void
    {
        // Build a CID-keyed minimal CFF (ROS + FDArray + FDSelect). The reader
        // must recognise ROS, populate cidKeyed, and leave namePrivate null.
        $bytes = $this->buildMinimalCidKeyedCff(numGlyphs: 4, fdSelectFormat: 0);
        $cff = (new CffReader())->read($bytes, 'CidFont');
        self::assertArrayHasKey('ROS', $cff->topDicts[0]);
        self::assertNull($cff->topDictData[0]->namePrivate);
        self::assertNotNull($cff->topDictData[0]->cidKeyed);
    }

    /** Build a minimal CID-keyed CFF with $numGlyphs glyphs, one FD, FDSelect $format (0 or 3). */
    private function buildMinimalCidKeyedCff(int $numGlyphs, int $fdSelectFormat): string
    {
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex(['CidFont']);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        $cs = self::buildIndex(array_fill(0, $numGlyphs, "\x0e"));
        $charset = "\x00";
        for ($g = 1; $g < $numGlyphs; $g++) {
            $charset .= pack('n', $g);
        }
        $fdPrivBody = '';
        $fdPrivSize = 0;
        $build = static function (int $cset, int $csOff, int $fda, int $fds): string {
            return "\x8C\x8C\x8B" . "\x0c\x1e"
                . "\x1d" . pack('N', $cset) . "\x0f"
                . "\x1d" . pack('N', $csOff) . "\x11"
                . "\x1d" . pack('N', $fda) . "\x0c\x24"
                . "\x1d" . pack('N', $fds) . "\x0c\x25";
        };
        $fontDictBuild = static fn (int $pSize, int $pOff): string =>
            self::encodeInt($pSize) . "\x1d" . pack('N', $pOff) . "\x12";
        // FDSelect data
        if ($fdSelectFormat === 0) {
            $fdSelect = "\x00" . str_repeat("\x00", $numGlyphs);
        } else {
            $fdSelect = "\x03" . pack('n', 1) . pack('n', 0) . "\x00" . pack('n', $numGlyphs);
        }
        // Use placeholder fontDict, derive fda length once.
        $fdaPlaceholder = self::buildIndex([$fontDictBuild(0, 0)]);
        $fdaLen = strlen($fdaPlaceholder);
        // Build with placeholder offsets to fix preamble length
        $topDictIndexP = self::buildIndex([$build(0, 0, 0, 0)]);
        $preambleLen = strlen($header . $nameIndex . $topDictIndexP . $stringIndex . $gsubrsIndex);
        $csetOff = $preambleLen;
        $csOff = $csetOff + strlen($charset);
        $fdaOff = $csOff + strlen($cs);
        $fdsOff = $fdaOff + $fdaLen;
        $fdPrivOff = $fdsOff + strlen($fdSelect);
        // final builds (long-form offsets are size-stable -> preamble length identical)
        $fda = self::buildIndex([$fontDictBuild($fdPrivSize, $fdPrivOff)]);
        $topDictIndex = self::buildIndex([$build($csetOff, $csOff, $fdaOff, $fdsOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        return $preamble . $charset . $cs . $fda . $fdSelect . $fdPrivBody;
    }

    public function testCidKeyedFdArrayAndFdSelectFormat0Decoded(): void
    {
        $bytes = $this->buildMinimalCidKeyedCff(numGlyphs: 4, fdSelectFormat: 0);
        $cff = (new CffReader())->read($bytes, 'CidFont');
        $td = $cff->topDictData[0];
        self::assertNotNull($td->cidKeyed);
        self::assertNull($td->namePrivate);
        self::assertCount(1, $td->cidKeyed->fontDicts);
        self::assertCount(1, $td->cidKeyed->fdPrivates);
        self::assertSame(0, $td->cidKeyed->fdSelectFormat);
        self::assertSame([0 => 0, 1 => 0, 2 => 0, 3 => 0], $td->cidKeyed->fdSelect);
    }

    public function testCidKeyedFdSelectFormat3Decoded(): void
    {
        $bytes = $this->buildMinimalCidKeyedCff(numGlyphs: 4, fdSelectFormat: 3);
        $cff = (new CffReader())->read($bytes, 'CidFont');
        $td = $cff->topDictData[0];
        self::assertNotNull($td->cidKeyed);
        self::assertSame(3, $td->cidKeyed->fdSelectFormat);
        self::assertSame([0 => 0, 1 => 0, 2 => 0, 3 => 0], $td->cidKeyed->fdSelect);
    }

    public function testRejectsUnknownFdSelectFormat(): void
    {
        $bytes = $this->buildMinimalCidKeyedCff(numGlyphs: 4, fdSelectFormat: 0);
        // Locate the FDSelect format byte 0x00 (5 bytes from the end: "\x00\x00\x00\x00\x00" = format 0 + 4 GID bytes; no trailing Private)
        // Patch the format byte to 99 (0x63):
        $patched = preg_replace('/\x00\x00\x00\x00\x00$/', "\x63\x00\x00\x00\x00", $bytes, 1);
        self::assertIsString($patched);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unsupported CFF FDSelect format 99');
        (new CffReader())->read($patched, 'CidFont');
    }
}
