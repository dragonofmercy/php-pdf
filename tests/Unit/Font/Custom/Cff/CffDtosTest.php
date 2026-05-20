<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffCharStrings;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffCharset;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffCidKeyed;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffEncoding;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffHeader;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffNameKeyedPrivate;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffTopDictData;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\ParsedCff;
use PHPUnit\Framework\TestCase;

final class CffDtosTest extends TestCase
{
    public function testHeaderHoldsFourFields(): void
    {
        $h = new CffHeader(major: 1, minor: 0, hdrSize: 4, offSize: 2);
        self::assertSame(1, $h->major);
        self::assertSame(0, $h->minor);
        self::assertSame(4, $h->hdrSize);
        self::assertSame(2, $h->offSize);
    }

    public function testCharsetCarriesGidMapAndFormat(): void
    {
        $c = new CffCharset(
            gidToNameOrCid: [0 => 0, 1 => 17, 2 => 23],
            format: 0,
            rawBytes: "\x00\x00\x11\x00\x17",
        );
        self::assertSame([0 => 0, 1 => 17, 2 => 23], $c->gidToNameOrCid);
        self::assertSame(0, $c->format);
        self::assertSame("\x00\x00\x11\x00\x17", $c->rawBytes);
    }

    public function testCharStringsCarriesSparseGlyphsAndNumGlyphs(): void
    {
        $cs = new CffCharStrings(glyphs: [0 => "\x0e", 36 => "\x21\x0e"], numGlyphs: 256);
        self::assertSame(256, $cs->numGlyphs);
        self::assertSame("\x21\x0e", $cs->glyphs[36]);
    }

    public function testEncodingCarriesRawBytes(): void
    {
        $e = new CffEncoding(rawBytes: "\x00\x00");
        self::assertSame("\x00\x00", $e->rawBytes);
    }

    public function testNameKeyedPrivateCarriesDictAndSubrs(): void
    {
        $p = new CffNameKeyedPrivate(
            privateDict: ['BlueValues' => [-10, 0, 700, 710]],
            localSubrs: ["\x0b"],
        );
        self::assertSame(['BlueValues' => [-10, 0, 700, 710]], $p->privateDict);
        self::assertSame(["\x0b"], $p->localSubrs);
    }

    public function testCidKeyedCarriesFontDictsPrivatesFdSelect(): void
    {
        $priv = new CffNameKeyedPrivate(privateDict: [], localSubrs: []);
        $k = new CffCidKeyed(
            fontDicts: [['Private' => [10, 100]]],
            fdPrivates: [$priv],
            fdSelect: [0 => 0, 1 => 0, 2 => 0],
            fdSelectFormat: 0,
            fdSelectRawBytes: "\x00\x00\x00\x00",
        );
        self::assertCount(1, $k->fontDicts);
        self::assertCount(1, $k->fdPrivates);
        self::assertSame(0, $k->fdSelect[1]);
        self::assertSame(0, $k->fdSelectFormat);
        self::assertSame("\x00\x00\x00\x00", $k->fdSelectRawBytes);
    }

    public function testTopDictDataCarriesSubsections(): void
    {
        $charset = new CffCharset([0 => 0], 0, "\x00");
        $cs = new CffCharStrings([0 => ''], 1);
        $td = new CffTopDictData(
            charset: $charset,
            encoding: null,
            charStrings: $cs,
            namePrivate: null,
            cidKeyed: null,
        );
        self::assertSame($charset, $td->charset);
        self::assertNull($td->encoding);
        self::assertSame($cs, $td->charStrings);
    }

    public function testParsedCffComposesEverything(): void
    {
        $header = new CffHeader(1, 0, 4, 1);
        $charset = new CffCharset([0 => 0], 0, "\x00");
        $cs = new CffCharStrings([0 => ''], 1);
        $td = new CffTopDictData($charset, null, $cs, null, null);
        $cff = new ParsedCff(
            header: $header,
            nameIndexEntry: 'IBMPlexSans',
            topDicts: [['CharStrings' => 0]],
            stringIndex: [],
            gsubrsIndex: [],
            topDictData: [$td],
        );
        self::assertSame('IBMPlexSans', $cff->nameIndexEntry);
        self::assertCount(1, $cff->topDictData);
        self::assertSame($header, $cff->header);
    }
}
