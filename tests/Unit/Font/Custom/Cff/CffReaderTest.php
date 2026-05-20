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

    public function testCidKeyedDiscriminationByRosOperator(): void
    {
        // Build a CID-keyed minimal CFF: same as name-keyed but Top DICT carries
        // operator ROS (0x0c 0x1e) with operands [registry SID=1, ordering SID=1, supplement=0].
        // For this read-only test we only need ROS to be present; FDArray/FDSelect
        // come in Task 5 (the reader populates cidKeyed there). Here we expect
        // the reader to recognise ROS and defer the cidKeyed payload to Task 5
        // by leaving cidKeyed null until Task 5 lands - so this test asserts:
        //   - operator ROS is in topDicts[0]
        //   - topDictData[0]->namePrivate is null
        // The cidKeyed assertion lives in Task 5.
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex(['CidFont']);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        $cs = self::buildIndex(array_fill(0, 4, "\x0e"));
        $build = static fn (int $charsetOff, int $csOff): string =>
            "\x8C\x8C\x8B"            // operands 1, 1, 0
            . "\x0c\x1e"               // operator ROS
            . "\x1d" . pack('N', $charsetOff) . "\x0f"
            . "\x1d" . pack('N', $csOff) . "\x11";
        $topDictIndex = self::buildIndex([$build(0, 0)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $charsetOff = strlen($preamble);
        $charset = "\x00" . pack('n', 1) . pack('n', 2) . pack('n', 3); // format 0
        $csOff = $charsetOff + strlen($charset);
        $topDictIndex = self::buildIndex([$build($charsetOff, $csOff)]);
        $preamble = $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
        $bytes = $preamble . $charset . $cs;

        $cff = (new CffReader())->read($bytes, 'CidFont');
        self::assertArrayHasKey('ROS', $cff->topDicts[0]);
        self::assertNull($cff->topDictData[0]->namePrivate);
    }
}
