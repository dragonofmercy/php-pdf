<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\GlyphClosure;
use DragonOfMercy\PhpPdf\Font\Custom\SfntReader;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\Font\Custom\TtfSubsetter;
use PHPUnit\Framework\TestCase;

final class TtfSubsetterTest extends TestCase
{
    private const string FREESANS = __DIR__ . '/../../../Golden/fixtures/fonts/FreeSans.ttf';

    private function freeSansBytes(): string
    {
        if (!is_file(self::FREESANS)) {
            self::markTestSkipped('FreeSans fixture absent');
        }
        $raw = file_get_contents(self::FREESANS);
        self::assertIsString($raw);
        return $raw;
    }

    /** @return array<int, true> */
    private function smallClosure(string $raw): array
    {
        $ttf = TtfParser::parse($raw, 'FreeSans');
        $used = [];
        foreach (['A', 'B', 'C', 'a', 'e'] as $ch) {
            $used[$ttf->cmap[ord($ch)]] = true;
        }
        return GlyphClosure::expand($raw, $used, 'FreeSans');
    }

    public function testSubsetIsReparseableAndMuchSmaller(): void
    {
        $raw = $this->freeSansBytes();
        $sub = TtfSubsetter::subset($raw, $this->smallClosure($raw), 'FreeSans');

        self::assertLessThan(strlen($raw) / 5, strlen($sub), 'subset should be far smaller');
        $reparsed = TtfParser::parse($sub, 'FreeSans-subset');
        self::assertSame('FreeSans', $reparsed->postScriptName);
    }

    public function testKeptTablesPresentDroppedTablesAbsent(): void
    {
        $raw = $this->freeSansBytes();
        $sub = TtfSubsetter::subset($raw, $this->smallClosure($raw), 'FreeSans');
        $dir = SfntReader::directory($sub, 'sub');

        foreach (['head', 'hhea', 'maxp', 'cmap', 'hmtx', 'name', 'OS/2', 'post', 'glyf', 'loca'] as $keep) {
            self::assertArrayHasKey($keep, $dir, "missing kept table {$keep}");
        }
        foreach (['fpgm', 'prep', 'cvt ', 'GSUB', 'GPOS', 'kern', 'GDEF'] as $dropped) {
            self::assertArrayNotHasKey($dropped, $dir, "dropped table {$dropped} still present");
        }
    }

    public function testLocaIsLongFormatAndHeadFlagPatched(): void
    {
        $raw = $this->freeSansBytes();
        $sub = TtfSubsetter::subset($raw, $this->smallClosure($raw), 'FreeSans');
        $dir = SfntReader::directory($sub, 'sub');
        self::assertSame(1, SfntReader::u16($sub, $dir['head']['offset'] + 50));
    }

    public function testHeadCheckSumAdjustmentIsValid(): void
    {
        $raw = $this->freeSansBytes();
        $sub = TtfSubsetter::subset($raw, $this->smallClosure($raw), 'FreeSans');
        $dir = SfntReader::directory($sub, 'sub');

        $headOff = $dir['head']['offset'];
        $adjustment = SfntReader::u32($sub, $headOff + 8);
        $zeroed = substr_replace($sub, "\x00\x00\x00\x00", $headOff + 8, 4);
        $words = unpack('N*', $zeroed);
        self::assertIsArray($words);
        $sum = 0;
        foreach ($words as $w) {
            $sum += is_int($w) ? $w : 0;
        }
        self::assertSame(0xB1B0AFBA, ($sum + $adjustment) & 0xFFFFFFFF);
    }

    public function testNumGlyphsAndCmapPreservedGidStable(): void
    {
        $raw = $this->freeSansBytes();
        $original = TtfParser::parse($raw, 'FreeSans');
        $sub = TtfSubsetter::subset($raw, $this->smallClosure($raw), 'FreeSans');
        $reparsed = TtfParser::parse($sub, 'FreeSans-subset');

        self::assertSame($original->cmap[ord('A')], $reparsed->cmap[ord('A')]);
        self::assertSame(
            count($original->advanceWidthsByGid),
            count($reparsed->advanceWidthsByGid),
        );
    }

    public function testSubsetIsByteDeterministic(): void
    {
        $raw = $this->freeSansBytes();
        $closure = $this->smallClosure($raw);
        self::assertSame(
            TtfSubsetter::subset($raw, $closure, 'FreeSans'),
            TtfSubsetter::subset($raw, $closure, 'FreeSans'),
        );
    }
}
