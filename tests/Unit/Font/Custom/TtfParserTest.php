<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use PHPUnit\Framework\TestCase;

final class TtfParserTest extends TestCase
{
    public function testRejectsOpenTypeCffWithClearMessage(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('OTF/CFF fonts not supported in this version, use TTF: Inter (regular)');
        TtfParser::parse("OTTO\x00\x00\x00\x00more bytes here", 'Inter (regular)');
    }

    public function testRejectsTrueTypeCollection(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('TrueType collection (.ttc) not supported');
        TtfParser::parse("ttcf\x00\x01\x00\x00more", 'Inter (regular)');
    }

    public function testRejectsUnknownMagicBytes(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid TTF file');
        TtfParser::parse("\xDE\xAD\xBE\xEFmore", 'Inter (regular)');
    }

    public function testRejectsTooShortInput(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid TTF file');
        TtfParser::parse('xy', 'Inter (regular)');
    }

    public function testRejectsMissingRequiredTable(): void
    {
        $sfnt = "\x00\x01\x00\x00";
        $numTables = pack('n', 1);
        $searchRange = pack('n', 16);
        $entrySelector = pack('n', 0);
        $rangeShift = pack('n', 0);
        $offsetTable = $sfnt . $numTables . $searchRange . $entrySelector . $rangeShift;
        $tableRecord = 'foo ' . pack('NNN', 0, 28, 4);
        $body = 'DATA';
        $bytes = $offsetTable . $tableRecord . $body;

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Missing required TTF table 'head' in test.ttf");
        TtfParser::parse($bytes, 'test.ttf');
    }

    public function testParsesValidFreeSansFile(): void
    {
        $path = __DIR__ . '/../../../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans.ttf fixture not present yet');
        }
        $parsed = TtfParser::parse((string) file_get_contents($path), 'FreeSans (regular)');
        self::assertSame('FreeSans', $parsed->postScriptName);
        self::assertGreaterThan(0, $parsed->unitsPerEm);
        self::assertNotEmpty($parsed->cmap);
        self::assertGreaterThan(0, $parsed->ascent);
        self::assertLessThan(0, $parsed->descent);
    }
}
