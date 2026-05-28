<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Font, Page};
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
final readonly class Ean13 implements OrientableBarcode, SizedBarcode
{
    use Orientable;
    /** Total module count including quiet zones: 11 (left quiet) + 95 (bars) + 7 (right quiet). */
    private const int TOTAL_MODULES = 113;

    private function __construct(
        public string $digits,
        public Color $color,
        public bool $showText,
        public Orientation $orientation = Orientation::Horizontal,
        public ?float $moduleSize = null,
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
        return new self($this->digits, $color, $this->showText, $this->orientation, $this->moduleSize);
    }

    public function withoutText(): self
    {
        return new self($this->digits, $this->color, false, $this->orientation, $this->moduleSize);
    }

    public function withOrientation(Orientation $orientation): self
    {
        return new self($this->digits, $this->color, $this->showText, $orientation, $this->moduleSize);
    }

    public function widthForModule(float $moduleSize): float
    {
        if ($moduleSize <= 0) {
            throw new PdfException("widthForModule expects a positive module size, got {$moduleSize}");
        }
        return self::TOTAL_MODULES * $moduleSize;
    }

    public function withModuleSize(float $moduleSize): self
    {
        if ($moduleSize <= 0) {
            throw new PdfException("Module size must be positive, got {$moduleSize}");
        }
        return new self($this->digits, $this->color, $this->showText, $this->orientation, $moduleSize);
    }

    public function intrinsicWidth(): ?float
    {
        return $this->moduleSize === null ? null : $this->widthForModule($this->moduleSize);
    }

    public function encode(): EncodedBarcode
    {
        $modules = $this->encodeModules();
        // EAN-13 asymmetric quiet zones: 11 left + 95 bars + 7 right = 113.
        $padded = array_merge(
            array_fill(0, 11, false),
            $modules,
            array_fill(0, 7, false),
        );

        $segments = [];
        if ($this->showText) {
            $first = $this->digits[0];
            $left  = substr($this->digits, 1, 6);
            $right = substr($this->digits, 7, 6);
            // Per ISO 15420 layout (matches the existing drawHumanText positions):
            //   - leading digit: anchor START at x=1 (inside left quiet zone)
            //   - 6-digit left block: anchor MIDDLE at x=35 (center of padded 14..56, width 42)
            //   - 6-digit right block: anchor MIDDLE at x=82 (center of padded 61..103, width 42)
            $segments[] = new HumanTextSegment($first, 1.0, 0.0, 0.0, TextAnchor::START);
            $segments[] = new HumanTextSegment($left, 35.0, 0.0, 0.0, TextAnchor::MIDDLE);
            $segments[] = new HumanTextSegment($right, 82.0, 0.0, 0.0, TextAnchor::MIDDLE);
        }

        return new EncodedBarcode(
            kind: BarcodeKind::LINEAR_1D,
            modules: $padded,
            humanTextSegments: $segments,
            color: $this->color,
            orientation: $this->orientation,
        );
    }

    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        if ($h === null) {
            throw new PdfException('Ean13 requires explicit h (height)');
        }

        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $hPt = $unit->toPoints($h);

        Renderer::oriented($page, $this->orientation, $xPt, $yPt, $wPt, $hPt, function () use ($page, $xPt, $yPt, $wPt, $hPt): void {
            $moduleW = $wPt / self::TOTAL_MODULES;
            // Standard EAN-13 typography per ISO 15420: the three guard bars
            // (left/centre/right) extend below the data bars, and the
            // human-readable digits sit at the level of that extension - 6+6
            // between the guards, first digit detached to the left in the quiet
            // zone. When ->withoutText() is set, all bars are at full height with
            // no extension.
            $barsHeight = $this->showText ? $hPt * 0.85 : $hPt;
            $extensionHeight = $hPt - $barsHeight;

            $modules = $this->encodeModules();
            // Pad with leading false for left quiet zone, trailing false for right.
            $padded = array_merge(
                array_fill(0, 11, false),
                $modules,
                array_fill(0, 7, false),
            );

            $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);

            if ($extensionHeight > 0.0) {
                // Guard ranges in padded coordinates: left 11..13 (3), centre 56..60 (5), right 103..105 (3).
                foreach ([[11, 3], [56, 5], [103, 3]] as [$start, $len]) {
                    $slice = array_slice($padded, $start, $len);
                    $body .= Renderer::runLengthRow(
                        $slice,
                        $xPt + $start * $moduleW,
                        $yPt + $barsHeight,
                        $moduleW,
                        $extensionHeight,
                    );
                }
            }

            $page->contentStream()->append(Renderer::wrap($body, $this->color));

            if ($this->showText) {
                $this->drawHumanText($page, $xPt, $yPt, $moduleW, $barsHeight, $extensionHeight);
            }
        });
    }

    /**
     * Draws the EAN-13 human-readable digits in the official layout:
     *   - first digit detached to the left, inside the quiet zone
     *   - 6 digits centred between left guard and centre guard (padded 14..55, 42 modules)
     *   - 6 digits centred between centre guard and right guard (padded 61..102, 42 modules)
     * Glyph baseline is centred vertically inside the guard-extension band.
     */
    private function drawHumanText(
        Page $page,
        float $xPt,
        float $yPt,
        float $moduleW,
        float $barsHeight,
        float $extensionHeight,
    ): void {
        $first = $this->digits[0];
        $left = substr($this->digits, 1, 6);
        $right = substr($this->digits, 7, 6);

        // Font: each 6-digit group occupies a 42-module zone (~7 modules per digit).
        // Capped at extensionHeight so the glyphs stay inside the extension band.
        $fontSize = min(12.0, 42 * $moduleW / 7.0, $extensionHeight);

        // Baseline vertically centred in the extension band.
        $textY = $yPt + $barsHeight + ($extensionHeight + $fontSize * 0.7) / 2;
        $textYUnit = $page->unit->fromPoints($textY);

        $page->save();
        $fontState = $page->captureFontState();
        $page->setFillColor($this->color);
        $page->setFont(Font::helvetica(), $fontSize);

        // First digit: 1 module from the left edge of the quiet zone.
        $firstX = $page->unit->fromPoints($xPt + $moduleW);
        $page->text($firstX, $textYUnit, $first);

        // Left half: 6 digits centred in padded 14..55 (42 modules wide).
        $leftStartX = $page->unit->fromPoints($xPt + 14 * $moduleW);
        $halfWidthUnit = $page->unit->fromPoints(42 * $moduleW);
        $leftWidth = $page->stringWidth($left);
        $leftX = $leftStartX + ($halfWidthUnit - $leftWidth) / 2;
        $page->text($leftX, $textYUnit, $left);

        // Right half: 6 digits centred in padded 61..102 (42 modules wide).
        $rightStartX = $page->unit->fromPoints($xPt + 61 * $moduleW);
        $rightWidth = $page->stringWidth($right);
        $rightX = $rightStartX + ($halfWidthUnit - $rightWidth) / 2;
        $page->text($rightX, $textYUnit, $right);

        $page->restoreFontState($fontState);
        $page->restore();
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
     * Returns the 95-element module sequence for this EAN-13.
     *
     * @internal
     * @return list<bool>
     */
    private function encodeModules(): array
    {
        $first = (int) $this->digits[0];
        $parity = EanSymbols::LEFT_PARITY[$first];
        $modules = [true, false, true]; // left guard 101
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $this->digits[1 + $i];
            $set = $parity[$i] === 'A' ? EanSymbols::SET_A : EanSymbols::SET_B;
            foreach ($set[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        // centre guard 01010
        array_push($modules, false, true, false, true, false);
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $this->digits[7 + $i];
            foreach (EanSymbols::SET_C[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        // right guard 101
        array_push($modules, true, false, true);
        return $modules;
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
