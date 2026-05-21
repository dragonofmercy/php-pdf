<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Galois field GF(2^k) for the five codeword sizes used by Aztec
 * (mode message uses k=4, data codewords use k in {6, 8, 10, 12}).
 *
 * Primitive polynomials per ISO/IEC 24778 Annex A.1:
 *   GF(16):   p(x) = x^4 + x + 1                     (0x13)
 *   GF(64):   p(x) = x^6 + x + 1                     (0x43)
 *   GF(256):  p(x) = x^8 + x^5 + x^3 + x^2 + 1       (0x12D)  -- NOT QR's 0x11D
 *   GF(1024): p(x) = x^10 + x^3 + 1                  (0x409)
 *   GF(4096): p(x) = x^12 + x^6 + x^5 + x^3 + 1      (0x1069)
 *
 * @internal
 */
final class GaloisField
{
    /** @var array<int, int> */
    private array $expTable;

    /** @var array<int, int> */
    private array $logTable;

    private function __construct(
        public readonly int $size,
        int $primitive,
    ) {
        /** @var array<int, int> $expTable */
        $expTable = array_fill(0, $this->size, 0);
        /** @var array<int, int> $logTable */
        $logTable = array_fill(0, $this->size, 0);
        $x = 1;
        for ($i = 0; $i < $this->size - 1; $i++) {
            $expTable[$i] = $x;
            $logTable[$x] = $i;
            $x <<= 1;
            if ($x >= $this->size) {
                $x ^= $primitive;
            }
        }
        // expTable[size-1] is unused but kept to make indexing safe for some callers.
        $expTable[$this->size - 1] = 1;
        $this->expTable = $expTable;
        $this->logTable = $logTable;
    }

    public static function gf16(): self { return new self(16, 0x13); }
    public static function gf64(): self { return new self(64, 0x43); }
    public static function gf256(): self { return new self(256, 0x12D); }
    public static function gf1024(): self { return new self(1024, 0x409); }
    public static function gf4096(): self { return new self(4096, 0x1069); }

    public static function forCodewordBits(int $bits): self
    {
        return match ($bits) {
            4  => self::gf16(),
            6  => self::gf64(),
            8  => self::gf256(),
            10 => self::gf1024(),
            12 => self::gf4096(),
            default => throw new PdfException("Unsupported Aztec codeword size: {$bits} bits"),
        };
    }

    public function size(): int { return $this->size; }

    public function exp(int $i): int
    {
        $idx = (($i % ($this->size - 1)) + ($this->size - 1)) % ($this->size - 1);
        return $this->expTable[$idx] ?? 0;
    }

    public function log(int $a): int
    {
        if ($a === 0) {
            throw new PdfException('log(0) is undefined in GF(2^k)');
        }
        return $this->logTable[$a] ?? 0;
    }

    public function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        $idx = ($this->logTable[$a] + $this->logTable[$b]) % ($this->size - 1);
        return $this->expTable[$idx] ?? 0;
    }

    public function inverse(int $a): int
    {
        if ($a === 0) {
            throw new PdfException('inverse(0) is undefined in GF(2^k)');
        }
        $idx = ($this->size - 1 - $this->logTable[$a]) % ($this->size - 1);
        return $this->expTable[$idx] ?? 0;
    }
}
