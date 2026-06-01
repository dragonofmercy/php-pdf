<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffOpenTypeSubsetter;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffReader;
use DragonOfMercy\PhpPdf\Font\Custom\SfntReader;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use PHPUnit\Framework\TestCase;

final class CffOpenTypeSubsetterTest extends TestCase
{
    private const string PLEX_OTF = __DIR__ . '/../../../../Golden/assets/fonts/IBMPlexSans-Regular.otf';

    public function testEndToEndPipelineProducesValidSmallerOtf(): void
    {
        if (!is_file(self::PLEX_OTF)) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $bytes = file_get_contents(self::PLEX_OTF);
        self::assertIsString($bytes);
        $parsed = TtfParser::parse($bytes, 'IBMPlexSans');
        $closure = [0 => true];
        foreach ([ord('A'), ord('B'), ord('C')] as $cp) {
            $gid = $parsed->cmap[$cp] ?? null;
            if (is_int($gid)) {
                $closure[$gid] = true;
            }
        }
        $subsetted = (new CffOpenTypeSubsetter())->subset($bytes, $closure, 'IBMPlexSans');
        self::assertLessThan(strlen($bytes) * 0.8, strlen($subsetted), 'subset OTF should be smaller');
        $origCff = SfntReader::extractTable($bytes, 'CFF ', 'IBMPlexSans');
        $newCff = SfntReader::extractTable($subsetted, 'CFF ', 'IBMPlexSans-sub');
        self::assertLessThan(strlen($origCff) * 0.5, strlen($newCff), 'subset CFF table should be much smaller');

        $reparsed = TtfParser::parse($subsetted, 'IBMPlexSans-sub');
        self::assertSame('IBMPlexSans', $reparsed->postScriptName);

        $cffBytes = SfntReader::extractTable($subsetted, 'CFF ', 'IBMPlexSans-sub');
        $cff = (new CffReader())->read($cffBytes, 'IBMPlexSans-sub');
        foreach ($closure as $gid => $_) {
            self::assertNotSame('', $cff->topDictData[0]->charStrings->glyphs[$gid] ?? '');
        }
    }

    public function testRejectsOtfWithoutCffTable(): void
    {
        $ttfPath = __DIR__ . '/../../../../Golden/assets/fonts/FreeSans.ttf';
        if (!is_file($ttfPath)) {
            self::markTestSkipped('FreeSans fixture absent');
        }
        $bytes = file_get_contents($ttfPath);
        self::assertIsString($bytes);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Missing 'CFF ' table");
        (new CffOpenTypeSubsetter())->subset($bytes, [0 => true], 'TtfNotOtf');
    }

    public function testHeadChecksumAdjustmentIsRecomputed(): void
    {
        if (!is_file(self::PLEX_OTF)) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $bytes = file_get_contents(self::PLEX_OTF);
        self::assertIsString($bytes);
        $subsetted = (new CffOpenTypeSubsetter())->subset($bytes, [0 => true], 'IBMPlexSans');

        $dir = SfntReader::directory($subsetted, 'rt');
        self::assertArrayHasKey('head', $dir);
        $headOff = $dir['head']['offset'];
        $adjustment = SfntReader::u32($subsetted, $headOff + 8);
        $zeroed = substr_replace($subsetted, "\x00\x00\x00\x00", $headOff + 8, 4);
        $sum = 0;
        $words = unpack('N*', $zeroed . str_repeat("\x00", (4 - strlen($zeroed) % 4) % 4));
        self::assertNotFalse($words);
        foreach ($words as $w) {
            self::assertIsInt($w);
            $sum = ($sum + $w) & 0xFFFFFFFF;
        }
        self::assertSame(0xB1B0AFBA, ($sum + $adjustment) & 0xFFFFFFFF);
    }
}
