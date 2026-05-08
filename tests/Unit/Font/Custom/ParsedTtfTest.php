<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use PHPUnit\Framework\TestCase;

final class ParsedTtfTest extends TestCase
{
    public function testHoldsAllProvidedFields(): void
    {
        $parsed = new ParsedTtf(
            bytes: 'TTFBYTES',
            postScriptName: 'FreeSans',
            unitsPerEm: 1000,
            ascent: 935,
            descent: -265,
            capHeight: 700,
            xHeight: 500,
            bbox: [-200, -200, 1100, 800],
            italicAngle: 0,
            weight: 400,
            flags: 32,
            cmap: [0x41 => 36, 0x42 => 37],
            advanceWidthsByGid: [0 => 500, 36 => 600, 37 => 580],
        );

        self::assertSame('TTFBYTES', $parsed->bytes);
        self::assertSame('FreeSans', $parsed->postScriptName);
        self::assertSame(1000, $parsed->unitsPerEm);
        self::assertSame(935, $parsed->ascent);
        self::assertSame(-265, $parsed->descent);
        self::assertSame(700, $parsed->capHeight);
        self::assertSame(500, $parsed->xHeight);
        self::assertSame([-200, -200, 1100, 800], $parsed->bbox);
        self::assertSame(0, $parsed->italicAngle);
        self::assertSame(400, $parsed->weight);
        self::assertSame(32, $parsed->flags);
        self::assertSame(36, $parsed->cmap[0x41]);
        self::assertSame(600, $parsed->advanceWidthsByGid[36]);
    }
}
