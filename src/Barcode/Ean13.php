<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * EAN-13 barcode (ISO/IEC 15420). 13 digits, last is a modulo-10 checksum.
 * Use {@see self::of()} for validated construction (12 digits -> auto checksum,
 * 13 digits -> checksum verified). {@see self::ofUnchecked()} skips validation.
 *
 * Default rendering: black bars + human-readable text below using the official
 * EAN-13 layout (first digit detached on the left, 6+6 below the two halves).
 * Disable the text with {@see self::withoutText()}.
 */
final readonly class Ean13 implements Barcode
{
    private function __construct(
        public string $digits,
        public Color $color,
        public bool $showText,
    ) {}

    public static function of(string $digits): self
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new PdfException('EAN-13 expects digits only');
        }
        $len = strlen($digits);
        if ($len !== 12 && $len !== 13) {
            throw new PdfException("EAN-13 expects 12 or 13 digits, got {$len}");
        }
        $expected = self::computeChecksum(substr($digits, 0, 12));
        if ($len === 13) {
            $given = (int) $digits[12];
            if ($given !== $expected) {
                throw new PdfException("EAN-13 checksum invalid: expected {$expected}, got {$given}");
            }
            return new self($digits, Color::rgb(0, 0, 0), true);
        }
        return new self($digits . (string) $expected, Color::rgb(0, 0, 0), true);
    }

    public static function ofUnchecked(string $digits): self
    {
        return new self($digits, Color::rgb(0, 0, 0), true);
    }

    public function withColor(Color $color): self
    {
        return new self($this->digits, $color, $this->showText);
    }

    public function withoutText(): self
    {
        return new self($this->digits, $this->color, false);
    }

    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        throw new PdfException('Ean13::draw() not yet implemented (Task 6)');
    }

    /**
     * EAN-13 checksum: sum of odd-position digits + 3 * sum of even-position
     * digits, modulo 10, complemented to 10 if non-zero. Positions are
     * 1-indexed from the left. ISO/IEC 15420 §A.4.
     *
     * @internal
     */
    public static function computeChecksum(string $twelveDigits): int
    {
        $odd = 0;
        $even = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int) $twelveDigits[$i];
            // Position 1 (i=0) is odd, position 2 (i=1) is even.
            if ($i % 2 === 0) {
                $odd += $d;
            } else {
                $even += $d;
            }
        }
        $sum = $odd + 3 * $even;
        return (10 - ($sum % 10)) % 10;
    }
}
