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
        $header = "\x01\x00\x04\x01"; // major=1 minor=0 hdrSize=4 offSize=1
        $nameIndex = self::buildIndex(['ABC']);
        // Top DICT: operand 1 (0x8C = 1+139) then operator 0x00 (version, SID 1)
        $topDictBytes = "\x8C\x00";
        $topDictIndex = self::buildIndex([$topDictBytes]);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        return $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
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

    private function wrapTopDict(string $topDictBytes, string $name): string
    {
        $header = "\x01\x00\x04\x01";
        $nameIndex = self::buildIndex([$name]);
        $topDictIndex = self::buildIndex([$topDictBytes]);
        $stringIndex = "\x00\x00";
        $gsubrsIndex = "\x00\x00";
        return $header . $nameIndex . $topDictIndex . $stringIndex . $gsubrsIndex;
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
}
