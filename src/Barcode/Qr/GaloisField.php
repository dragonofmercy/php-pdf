<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

/**
 * GF(256) arithmetic with primitive polynomial 0x11D
 * (x^8 + x^4 + x^3 + x^2 + 1), per ISO 18004 Section 6.5.1.
 *
 * Tables EXP[0..511] and LOG[0..255] are computed once at class load.
 *
 * @internal
 */
final class GaloisField
{
    /** @var array<int<0, 511>, int> 512-entry table; EXP[i + 255] == EXP[i] for easy mul. */
    private static array $exp;

    /** @var array<int, int> 256-entry table; LOG[0] is meaningless (set to 0). */
    private static array $log;

    private static bool $initialised = false;

    private static function init(): void
    {
        if (self::$initialised) {
            return;
        }
        /** @var array<int<0, 511>, int> $exp */
        $exp = array_fill(0, 512, 0);
        /** @var array<int, int> $log */
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
        self::$initialised = true;
    }

    public static function exp(int $i): int
    {
        self::init();
        $idx = (($i % 255) + 255) % 255;
        return self::$exp[$idx];
    }

    public static function log(int $v): int
    {
        self::init();
        if ($v <= 0 || $v > 255) {
            throw new \InvalidArgumentException('log() argument must be in 1..255');
        }
        return self::$log[$v];
    }

    /**
     * @param int<0, 255> $a
     * @param int<0, 255> $b
     */
    public static function mul(int $a, int $b): int
    {
        self::init();
        if ($a === 0 || $b === 0) {
            return 0;
        }
        $la = self::$log[$a];
        $lb = self::$log[$b];
        return self::$exp[$la + $lb];
    }
}
