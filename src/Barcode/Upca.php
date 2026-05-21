<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Font, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * UPC-A barcode (ISO/IEC 15420). 12 digits, last is a modulo-10 checksum.
 * {@see self::of()} validates (11 digits -> auto checksum, 12 -> verified);
 * {@see self::ofUnchecked()} skips validation.
 *
 * Bars: left 6 digits in EAN set A, right 6 in set C, guards 101/01010/101.
 * Human text uses the UPC layout: number-system digit detached on the left,
 * 5+5 under the halves, check digit detached on the right.
 *
 * @internal Quiet zone is 9 modules each side per the symbology.
 */
final readonly class Upca implements Barcode
{
    /** 9 (left quiet) + 95 (bars) + 9 (right quiet). */
    private const int TOTAL_MODULES = 113;
    private const int QUIET = 9;

    private function __construct(
        public string $digits,
        public Color $color,
        public bool $showText,
    ) {}

    public static function of(string $digits): self
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new PdfException('UPC-A expects digits only');
        }
        $len = strlen($digits);
        if ($len !== 11 && $len !== 12) {
            throw new PdfException("UPC-A expects 11 or 12 digits, got {$len}");
        }
        $expected = self::computeChecksum(substr($digits, 0, 11));
        if ($len === 12) {
            $given = (int) $digits[11];
            if ($given !== $expected) {
                throw new PdfException("UPC-A checksum invalid: expected {$expected}, got {$given}");
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

    public function widthForModule(float $moduleSize): float
    {
        if ($moduleSize <= 0) {
            throw new PdfException("widthForModule expects a positive module size, got {$moduleSize}");
        }
        return self::TOTAL_MODULES * $moduleSize;
    }

    /**
     * UPC-A checksum: (sum of digits at odd positions)*3 + sum at even
     * positions, mod 10, complemented. Positions 1-indexed from the left
     * over the first 11 digits. ISO/IEC 15420.
     *
     * @internal
     */
    public static function computeChecksum(string $elevenDigits): int
    {
        $odd = 0;
        $even = 0;
        for ($i = 0; $i < 11; $i++) {
            $d = (int) $elevenDigits[$i];
            if ($i % 2 === 0) {
                $odd += $d;
            } else {
                $even += $d;
            }
        }
        $sum = $odd * 3 + $even;
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
     * Returns the 95-element module sequence for this UPC-A.
     *
     * @internal
     * @return list<bool>
     */
    private function encodeModules(): array
    {
        $modules = [true, false, true];
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $this->digits[$i];
            foreach (EanSymbols::SET_A[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        array_push($modules, false, true, false, true, false);
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $this->digits[6 + $i];
            foreach (EanSymbols::SET_C[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        array_push($modules, true, false, true);
        return $modules;
    }

    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        if ($h === null) {
            throw new PdfException('Upca requires explicit h (height)');
        }
        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $hPt = $unit->toPoints($h);

        $moduleW = $wPt / self::TOTAL_MODULES;
        $barsHeight = $hPt * 0.85;
        $textHeight = $hPt - $barsHeight;

        $modules = $this->encodeModules();
        $padded = array_merge(
            array_fill(0, self::QUIET, false),
            $modules,
            array_fill(0, self::QUIET, false),
        );

        $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);
        $page->contentStream()->append(Renderer::wrap($body, $this->color));

        if ($this->showText) {
            $this->drawHumanText($page, $xPt, $yPt, $moduleW, $barsHeight, $textHeight);
        }
    }

    /**
     * Draws the UPC-A human-readable digits in the official layout:
     *   - number-system digit detached to the left (inside quiet zone)
     *   - 5 digits centred under the left half
     *   - 5 digits centred under the right half
     *   - check digit detached to the right (inside quiet zone)
     */
    private function drawHumanText(
        Page $page,
        float $xPt,
        float $yPt,
        float $moduleW,
        float $barsHeight,
        float $textHeight,
    ): void {
        $first = $this->digits[0];
        $left = substr($this->digits, 1, 5);
        $right = substr($this->digits, 6, 5);
        $last = $this->digits[11];

        $fontSize = min(12.0, (self::TOTAL_MODULES * $moduleW) / 14.0);
        $textY = $yPt + $barsHeight + ($textHeight - $fontSize * 0.7) / 2 + $fontSize * 0.7;
        $textYUnit = $page->unit->fromPoints($textY);

        $page->save();
        $page->setFillColor($this->color);
        $page->setFont(Font::helvetica(), $fontSize);

        $firstX = $page->unit->fromPoints($xPt + $moduleW);
        $page->text($firstX, $textYUnit, $first);

        $leftStart = $page->unit->fromPoints($xPt + (self::QUIET + 3) * $moduleW);
        $halfWidthUnit = $page->unit->fromPoints(35 * $moduleW);
        $leftW = $page->stringWidth($left);
        $page->text($leftStart + ($halfWidthUnit - $leftW) / 2, $textYUnit, $left);

        $rightStart = $page->unit->fromPoints($xPt + (self::QUIET + 3 + 42 + 5) * $moduleW);
        $rightW = $page->stringWidth($right);
        $page->text($rightStart + ($halfWidthUnit - $rightW) / 2, $textYUnit, $right);

        $lastX = $page->unit->fromPoints($xPt + (self::QUIET + 95 + 1) * $moduleW);
        $page->text($lastX, $textYUnit, $last);

        $page->restore();
    }
}
