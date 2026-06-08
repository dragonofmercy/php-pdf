<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\OutlineFormat;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtfCache;
use PHPUnit\Framework\TestCase;

final class ParsedTtfCacheTest extends TestCase
{
    private const string FONT = __DIR__ . '/../../../Golden/assets/fonts/FreeSans.ttf';

    private ?string $tempFont = null;

    protected function setUp(): void
    {
        ParsedTtfCache::clear();
    }

    protected function tearDown(): void
    {
        ParsedTtfCache::clear();
        if ($this->tempFont !== null && is_file($this->tempFont)) {
            unlink($this->tempFont);
        }
        $this->tempFont = null;
    }

    private function makeDummy(): ParsedTtf
    {
        return new ParsedTtf(
            bytes: 'stub',
            postScriptName: 'Stub',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            capHeight: 700,
            xHeight: 500,
            bbox: [0, 0, 1000, 1000],
            italicAngle: 0,
            weight: 400,
            flags: 0x20,
            cmap: [],
            advanceWidthsByGid: [],
            outlineFormat: OutlineFormat::TrueType,
        );
    }

    public function testLookupReturnsNullWhenCold(): void
    {
        self::assertNull(ParsedTtfCache::lookup(self::FONT));
    }

    public function testStoreThenLookupReturnsSameInstance(): void
    {
        $dummy = $this->makeDummy();
        ParsedTtfCache::store(self::FONT, $dummy);
        self::assertSame($dummy, ParsedTtfCache::lookup(self::FONT));
    }

    public function testClearEmptiesCache(): void
    {
        ParsedTtfCache::store(self::FONT, $this->makeDummy());
        ParsedTtfCache::clear();
        self::assertNull(ParsedTtfCache::lookup(self::FONT));
    }

    public function testMtimeChangeMisses(): void
    {
        $this->tempFont = tempnam(sys_get_temp_dir(), 'ttfcache') ?: null;
        self::assertNotNull($this->tempFont);
        copy(self::FONT, $this->tempFont);

        $dummy = $this->makeDummy();
        ParsedTtfCache::store($this->tempFont, $dummy);
        self::assertSame($dummy, ParsedTtfCache::lookup($this->tempFont));

        // Force a different mtime: the key changes, so the entry no longer matches.
        touch($this->tempFont, time() + 10);
        clearstatcache(true, $this->tempFont);
        self::assertNull(ParsedTtfCache::lookup($this->tempFont));
    }
}
