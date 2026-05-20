<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffCharStrings;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffCharset;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffCidKeyed;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffHeader;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffNameKeyedPrivate;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffSubsetter;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffTopDictData;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\ParsedCff;
use PHPUnit\Framework\TestCase;

final class CffSubsetterTest extends TestCase
{
    private function nameKeyed(): ParsedCff
    {
        $charset = new CffCharset([0 => 0, 1 => 17, 2 => 23, 3 => 31], 0, "\x00\x00\x11\x00\x17\x00\x1f");
        $cs = new CffCharStrings([
            0 => "\x0e",
            1 => "\xff\x0e",
            2 => "\x00\x0e",
            3 => "\x21\x0e",
        ], numGlyphs: 4);
        $priv = new CffNameKeyedPrivate(['BlueValues' => [0]], ["\x0b"]);
        $td = new CffTopDictData($charset, null, $cs, $priv, null);
        return new ParsedCff(
            header: new CffHeader(1, 0, 4, 1),
            nameIndexEntry: 'Plex',
            topDicts: [['CharStrings' => 0]],
            stringIndex: ['hello'],
            gsubrsIndex: ["\x0b"],
            topDictData: [$td],
        );
    }

    private function cidKeyed(): ParsedCff
    {
        $charset = new CffCharset([0 => 0, 1 => 1, 2 => 2, 3 => 3], 0, "\x00\x00\x01\x00\x02\x00\x03");
        $cs = new CffCharStrings([
            0 => "\x0e",
            1 => "\x10\x0e",
            2 => "\x20\x0e",
            3 => "\x30\x0e",
        ], numGlyphs: 4);
        $priv = new CffNameKeyedPrivate([], []);
        $cid = new CffCidKeyed(
            fontDicts: [['Private' => [0, 0]]],
            fdPrivates: [$priv],
            fdSelect: [0 => 0, 1 => 0, 2 => 0, 3 => 0],
            fdSelectFormat: 3,
            fdSelectRawBytes: "\x03\x00\x01\x00\x00\x00\x00\x04",
        );
        $td = new CffTopDictData($charset, null, $cs, null, $cid);
        return new ParsedCff(
            header: new CffHeader(1, 0, 4, 2),
            nameIndexEntry: 'CidFont',
            topDicts: [['ROS' => [1, 1, 0]]],
            stringIndex: [],
            gsubrsIndex: [],
            topDictData: [$td],
        );
    }

    public function testNameKeyedReducesOnlyCharStringsToClosure(): void
    {
        $sub = (new CffSubsetter())->subset($this->nameKeyed(), [0 => true, 2 => true], 'Plex');
        $td = $sub->topDictData[0];
        self::assertSame([0 => "\x0e", 2 => "\x00\x0e"], $td->charStrings->glyphs);
        self::assertSame(4, $td->charStrings->numGlyphs);
        // charset preserved
        self::assertSame([0 => 0, 1 => 17, 2 => 23, 3 => 31], $td->charset->gidToNameOrCid);
        // private preserved
        self::assertNotNull($td->namePrivate);
        self::assertSame(["\x0b"], $td->namePrivate->localSubrs);
    }

    public function testCidKeyedReducesOnlyCharStringsToClosure(): void
    {
        $sub = (new CffSubsetter())->subset($this->cidKeyed(), [0 => true, 3 => true], 'CidFont');
        $td = $sub->topDictData[0];
        self::assertSame([0 => "\x0e", 3 => "\x30\x0e"], $td->charStrings->glyphs);
        self::assertSame(4, $td->charStrings->numGlyphs);
        // CID payload preserved
        self::assertNotNull($td->cidKeyed);
        self::assertSame([0 => 0, 1 => 0, 2 => 0, 3 => 0], $td->cidKeyed->fdSelect);
        self::assertSame(3, $td->cidKeyed->fdSelectFormat);
    }

    public function testFullClosureLeavesCharStringsIdentical(): void
    {
        $orig = $this->nameKeyed();
        $sub = (new CffSubsetter())->subset($orig, [0 => true, 1 => true, 2 => true, 3 => true], 'Plex');
        self::assertSame(
            $orig->topDictData[0]->charStrings->glyphs,
            $sub->topDictData[0]->charStrings->glyphs,
        );
    }

    public function testNotdefOnlyClosureKeepsGid0(): void
    {
        $sub = (new CffSubsetter())->subset($this->nameKeyed(), [0 => true], 'Plex');
        self::assertSame([0 => "\x0e"], $sub->topDictData[0]->charStrings->glyphs);
        self::assertSame(4, $sub->topDictData[0]->charStrings->numGlyphs);
    }

    public function testOutOfBoundsGidThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Closure GID 99 not present in CFF CharStrings for Plex');
        (new CffSubsetter())->subset($this->nameKeyed(), [0 => true, 99 => true], 'Plex');
    }

    public function testGsubrsAndStringIndexUntouched(): void
    {
        $sub = (new CffSubsetter())->subset($this->nameKeyed(), [0 => true, 1 => true], 'Plex');
        self::assertSame(['hello'], $sub->stringIndex);
        self::assertSame(["\x0b"], $sub->gsubrsIndex);
    }

    public function testHeaderAndNameUntouched(): void
    {
        $orig = $this->nameKeyed();
        $sub = (new CffSubsetter())->subset($orig, [0 => true], 'Plex');
        self::assertSame($orig->header, $sub->header);
        self::assertSame('Plex', $sub->nameIndexEntry);
    }
}
