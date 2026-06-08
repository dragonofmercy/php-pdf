<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Process-global memoization of parsed TTF fonts. A {@see ParsedTtf} is
 * immutable and a pure function of the font file bytes, so a single instance
 * can be shared by every Document built in the same PHP process. Keying on
 * realpath + filesize + mtime lets a cache hit skip both the file read and the
 * parse.
 *
 * The cache lives for the duration of the process. On shared web hosting
 * (mod_php / php-fpm) PHP static state is reset between requests, so the cache
 * never accumulates across requests; it only helps long-lived CLI / worker
 * processes. {@see self::clear()} resets it (tests, long-worker memory control).
 *
 * Final but intentionally NOT readonly: it holds a mutable static array.
 *
 * @internal Only clear() is intended for public use.
 */
final class ParsedTtfCache
{
    /** @var array<string, ParsedTtf> */
    private static array $cache = [];

    public static function lookup(string $path): ?ParsedTtf
    {
        $key = self::keyFor($path);
        if ($key === null) {
            return null;
        }
        return self::$cache[$key] ?? null;
    }

    public static function store(string $path, ParsedTtf $parsed): void
    {
        $key = self::keyFor($path);
        if ($key === null) {
            return;
        }
        self::$cache[$key] = $parsed;
    }

    public static function clear(): void
    {
        self::$cache = [];
    }

    private static function keyFor(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if ($size === false || $mtime === false) {
            return null;
        }
        return $real . '|' . $size . '|' . $mtime;
    }
}
