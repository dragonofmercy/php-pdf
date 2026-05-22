<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Interleaved 2 of 5 barcode (ISO/IEC 16390). Digits only, even count.
 * Each digit is a 5-element 2-of-5 pattern (2 wide, 3 narrow); pairs are
 * interleaved (first digit = bars, second = spaces). Module model:
 * narrow = 1, wide = 3. {@see self::of()} requires an even length and adds
 * no checksum; {@see self::ofGtin14()} is the opt-in GS1 mod-10 path.
 *
 * @internal
 */
final readonly class Itf implements OrientableBarcode
{
    use Orientable;
    private const int QUIET_MODULES = 10;

    /** 2-of-5 width pattern per digit: 5 elements, 'n' narrow / 'w' wide. */
    private const array PATTERNS = [
        '0' => 'nnwwn', '1' => 'wnnnw', '2' => 'nwnnw', '3' => 'wwnnn', '4' => 'nnwnw',
        '5' => 'wnwnn', '6' => 'nwwnn', '7' => 'nnnww', '8' => 'wnnwn', '9' => 'nwnwn',
    ];

    private function __construct(
        public string $digits,
        public Color $color,
        public bool $showText,
        public ?float $bearerBarModules = null,
        public Orientation $orientation = Orientation::Horizontal,
    ) {}

    public static function of(string $digits): self
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new PdfException('ITF expects digits only');
        }
        $len = strlen($digits);
        if ($len % 2 !== 0) {
            throw new PdfException("ITF expects an even number of digits, got {$len}");
        }
        return new self($digits, Color::rgb(0, 0, 0), true);
    }

    public static function ofGtin14(string $digits): self
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new PdfException('ITF expects digits only');
        }
        $len = strlen($digits);
        if ($len !== 13 && $len !== 14) {
            throw new PdfException("ITF GTIN-14 expects 13 or 14 digits, got {$len}");
        }
        $expected = self::gtinChecksum(substr($digits, 0, 13));
        if ($len === 14) {
            $given = (int) $digits[13];
            if ($given !== $expected) {
                throw new PdfException("ITF GTIN-14 checksum invalid: expected {$expected}, got {$given}");
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
        return new self($this->digits, $color, $this->showText, $this->bearerBarModules, $this->orientation);
    }

    public function withoutText(): self
    {
        return new self($this->digits, $this->color, false, $this->bearerBarModules, $this->orientation);
    }

    /**
     * Add a GS1-style full-frame bearer bar around the symbol (anti short-scan).
     * Thickness is expressed in modules; null applies the GS1 default of 2.
     */
    public function withBearerBar(?float $modules = null): self
    {
        $thickness = $modules ?? 2.0;
        if ($thickness <= 0) {
            throw new PdfException("ITF bearer bar thickness must be positive, got {$thickness}");
        }
        return new self($this->digits, $this->color, $this->showText, $thickness, $this->orientation);
    }

    public function withOrientation(Orientation $orientation): self
    {
        return new self($this->digits, $this->color, $this->showText, $this->bearerBarModules, $orientation);
    }

    public function widthForModule(float $moduleSize): float
    {
        if ($moduleSize <= 0) {
            throw new PdfException("widthForModule expects a positive module size, got {$moduleSize}");
        }
        $modules = $this->encodeModules();
        return (count($modules) + 2 * self::QUIET_MODULES) * $moduleSize;
    }

    /**
     * GS1 mod-10 check digit over 13 digits: from the right-most data digit,
     * multiply alternately by 3 and 1, sum, complement to 10.
     *
     * @internal
     */
    public static function gtinChecksum(string $thirteenDigits): int
    {
        $sum = 0;
        for ($i = 12; $i >= 0; $i--) {
            $d = (int) $thirteenDigits[$i];
            // (12 - $i) is the 0-based position from the right; even position -> weight 3.
            $sum += ((12 - $i) % 2 === 0) ? $d * 3 : $d;
        }
        return (10 - ($sum % 10)) % 10;
    }

    /**
     * @internal Test access.
     * @return list<bool>
     */
    public function encodeModulesForTest(): array
    {
        return $this->encodeModules();
    }

    /**
     * @return list<bool>
     */
    private function encodeModules(): array
    {
        $modules = [true, false, true, false]; // start: nnnn (narrow bar, space, bar, space)

        $len = strlen($this->digits);
        for ($p = 0; $p < $len; $p += 2) {
            $barPat = self::PATTERNS[$this->digits[$p]];
            $spacePat = self::PATTERNS[$this->digits[$p + 1]];
            for ($e = 0; $e < 5; $e++) {
                $barWidth = $barPat[$e] === 'w' ? 3 : 1;
                for ($k = 0; $k < $barWidth; $k++) {
                    $modules[] = true;
                }
                $spaceWidth = $spacePat[$e] === 'w' ? 3 : 1;
                for ($k = 0; $k < $spaceWidth; $k++) {
                    $modules[] = false;
                }
            }
        }

        array_push($modules, true, true, true, false, true); // stop: wnn (wide bar, narrow space, narrow bar)
        return $modules;
    }

    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        $modules = $this->encodeModules();
        Linear1d::draw(
            $page,
            $x,
            $y,
            $w,
            $h,
            $modules,
            self::QUIET_MODULES,
            $this->color,
            $this->showText ? $this->digits : null,
            'Itf',
            $this->bearerBarModules,
            orientation: $this->orientation,
        );
    }
}
