<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\OutlineFormat;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtfCache;
use PHPUnit\Framework\TestCase;

final class ParsedTtfCacheTest extends TestCase
{
    private string $font;

    protected function setUp(): void
    {
        ParsedTtfCache::clear();
        $tmp = tempnam(sys_get_temp_dir(), 'ttfcache');
        if ($tmp === false) {
            self::fail('Could not create a temp file for the cache test');
        }
        $this->font = $tmp;
        file_put_contents($this->font, 'dummy-font-bytes');
    }

    protected function tearDown(): void
    {
        ParsedTtfCache::clear();
        if (is_file($this->font)) {
            unlink($this->font);
        }
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

    public function testParsesOnceThenReturnsCachedInstance(): void
    {
        $dummy = $this->makeDummy();
        $calls = 0;
        $parse = function () use ($dummy, &$calls): ParsedTtf {
            $calls++;
            return $dummy;
        };

        $first = ParsedTtfCache::getOrParse($this->font, $parse);
        $second = ParsedTtfCache::getOrParse($this->font, $parse);

        self::assertSame($dummy, $first);
        self::assertSame($dummy, $second);
        self::assertSame(1, $calls);
    }

    public function testClearForcesReparse(): void
    {
        $calls = 0;
        $parse = function () use (&$calls): ParsedTtf {
            $calls++;
            return $this->makeDummy();
        };

        ParsedTtfCache::getOrParse($this->font, $parse);
        ParsedTtfCache::clear();
        ParsedTtfCache::getOrParse($this->font, $parse);

        self::assertSame(2, $calls);
    }

    public function testMtimeChangeForcesReparse(): void
    {
        $calls = 0;
        $parse = function () use (&$calls): ParsedTtf {
            $calls++;
            return $this->makeDummy();
        };

        ParsedTtfCache::getOrParse($this->font, $parse);

        // Force a different mtime: the key changes, so the cached entry no
        // longer matches and $parse runs again.
        touch($this->font, time() + 10);
        clearstatcache(true, $this->font);
        ParsedTtfCache::getOrParse($this->font, $parse);

        self::assertSame(2, $calls);
    }

    public function testUncomputableKeyAlwaysParses(): void
    {
        $calls = 0;
        $parse = function () use (&$calls): ParsedTtf {
            $calls++;
            return $this->makeDummy();
        };

        // realpath() fails on a non-existent path: no key, so nothing is cached
        // and $parse runs on every call.
        $missing = $this->font . '.does-not-exist';
        ParsedTtfCache::getOrParse($missing, $parse);
        ParsedTtfCache::getOrParse($missing, $parse);

        self::assertSame(2, $calls);
    }
}
