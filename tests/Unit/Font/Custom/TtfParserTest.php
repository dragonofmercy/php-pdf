<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\OutlineFormat;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use PHPUnit\Framework\TestCase;

final class TtfParserTest extends TestCase
{
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
        // 'glyf' makes detectOutlineFormat return TrueType so parse() proceeds to requireTable('head'), the path under test.
        $tableRecord = 'glyf' . pack('NNN', 0, 28, 4);
        $body = 'DATA';
        $bytes = $offsetTable . $tableRecord . $body;

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Missing required TTF table 'head' in test.ttf");
        TtfParser::parse($bytes, 'test.ttf');
    }

    public function testParsesValidFreeSansFile(): void
    {
        $path = __DIR__ . '/../../../Golden/assets/fonts/FreeSans.ttf';
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

    private const string IBMPLEX_OTF = __DIR__ . '/../../../Golden/assets/fonts/IBMPlexSans-Regular.otf';

    public function testExistingTrueTypeFontReportsTrueTypeOutline(): void
    {
        $path = __DIR__ . '/../../../Golden/assets/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture absent');
        }
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $ttf = TtfParser::parse($raw, 'FreeSans');
        self::assertSame(OutlineFormat::TrueType, $ttf->outlineFormat);
    }

    public function testOtfCffFontIsAcceptedAndReportsCffOutline(): void
    {
        if (!is_file(self::IBMPLEX_OTF)) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $raw = file_get_contents(self::IBMPLEX_OTF);
        self::assertIsString($raw);
        $otf = TtfParser::parse($raw, 'IBMPlexSans');
        self::assertSame(OutlineFormat::Cff, $otf->outlineFormat);
        self::assertNotSame('', $otf->postScriptName);
    }

    public function testCff2VariableFontRejected(): void
    {
        if (!is_file(self::IBMPLEX_OTF)) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $raw = file_get_contents(self::IBMPLEX_OTF);
        self::assertIsString($raw);
        $mutated = self::renameTableTag($raw, 'CFF ', 'CFF2');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("OpenType CFF2 (variable) fonts not supported for X");
        TtfParser::parse($mutated, 'X');
    }

    public function testOttoWithoutCffRejected(): void
    {
        if (!is_file(self::IBMPLEX_OTF)) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $raw = file_get_contents(self::IBMPLEX_OTF);
        self::assertIsString($raw);
        $mutated = self::renameTableTag($raw, 'CFF ', 'ZZZZ');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Invalid OpenType font (OTTO without 'CFF ' table) for X");
        TtfParser::parse($mutated, 'X');
    }

    public function testCffDetectionIsMagicIndependent(): void
    {
        if (!is_file(self::IBMPLEX_OTF)) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $raw = file_get_contents(self::IBMPLEX_OTF);
        self::assertIsString($raw);
        $mutated = "\x00\x01\x00\x00" . substr($raw, 4);
        $otf = TtfParser::parse($mutated, 'X');
        self::assertSame(OutlineFormat::Cff, $otf->outlineFormat);
    }

    /**
     * Replaces the first occurrence of a 4-byte table tag in the sfnt table
     * directory (entries start at offset 12, 16 bytes each) with another
     * 4-byte tag. Tags are unique in a valid sfnt so the first hit is the one.
     */
    private static function renameTableTag(string $sfnt, string $from, string $to): string
    {
        $numTables = unpack('n', substr($sfnt, 4, 2));
        self::assertIsArray($numTables);
        $n = $numTables[1];
        for ($i = 0; $i < $n; $i++) {
            $pos = 12 + $i * 16;
            if (substr($sfnt, $pos, 4) === $from) {
                return substr_replace($sfnt, $to, $pos, 4);
            }
        }
        self::fail("tag {$from} not found in table directory");
    }
}
