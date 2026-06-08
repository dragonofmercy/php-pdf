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
 * @internal The class is internal; clear() is the supported reset for
 *           long-running workers.
 */
final class ParsedTtfCache
{
    /** @var array<string, ParsedTtf> */
    private static array $cache = [];

    /**
     * Returns the cached ParsedTtf for $path, or invokes $parse to produce and
     * cache it on a miss. $parse runs only on a miss, so a cache hit skips both
     * the file read and the parse. When the path cannot be stat-ed, the result
     * is parsed but not cached (graceful degradation).
     *
     * @param callable(): ParsedTtf $parse
     */
    public static function getOrParse(string $path, callable $parse): ParsedTtf
    {
        $key = self::keyFor($path);
        if ($key === null) {
            return $parse();
        }
        return self::$cache[$key] ??= $parse();
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
        $stat = @stat($real);
        if ($stat === false) {
            return null;
        }
        return $real . '|' . $stat['size'] . '|' . $stat['mtime'];
    }
}
