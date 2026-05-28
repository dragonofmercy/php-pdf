<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Font, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * EAN-8 barcode (ISO/IEC 15420). 8 digits, last is a modulo-10 checksum.
 * Use {@see self::of()} for validated construction (7 digits -> auto checksum,
 * 8 digits -> checksum verified). {@see self::ofUnchecked()} skips validation.
 *
 * Default rendering: black bars + human-readable text below (4+4 layout).
 * Disable the text with {@see self::withoutText()}.
 */
final readonly class Ean8 implements OrientableBarcode, SizedBarcode
{
    use Orientable;
    /** Total module count including 7+7 quiet zones (7 + 3+28+5+28+3 + 7 = 81). */
    private const int TOTAL_MODULES = 81;

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
            throw new PdfException('EAN-8 expects digits only');
        }
        $len = strlen($digits);
        if ($len !== 7 && $len !== 8) {
            throw new PdfException("EAN-8 expects 7 or 8 digits, got {$len}");
        }
        $expected = self::computeChecksum(substr($digits, 0, 7));
        if ($len === 8) {
            $given = (int) $digits[7];
            if ($given !== $expected) {
                throw new PdfException("EAN-8 checksum invalid: expected {$expected}, got {$given}");
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

    /**
     * EAN-8 checksum: 3*d1 + d2 + 3*d3 + d4 + 3*d5 + d6 + 3*d7, mod 10, complement.
     * @internal
     */
    public static function computeChecksum(string $sevenDigits): int
    {
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = (int) $sevenDigits[$i];
            $sum += ($i % 2 === 0) ? 3 * $d : $d;
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
        // EAN-8 always uses set A on the left side and set C on the right.
        $modules = [true, false, true]; // left guard 101
        for ($i = 0; $i < 4; $i++) {
            $d = (int) $this->digits[$i];
            foreach (EanSymbols::SET_A[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        array_push($modules, false, true, false, true, false); // centre guard 01010
        for ($i = 0; $i < 4; $i++) {
            $d = (int) $this->digits[4 + $i];
            foreach (EanSymbols::SET_C[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        array_push($modules, true, false, true); // right guard 101
        return $modules;
    }

    public function encode(): EncodedBarcode
    {
        $modules = $this->encodeModules();
        // EAN-8 quiet zones: 7 left + 67 bars + 7 right = 81.
        $padded = array_merge(
            array_fill(0, 7, false),
            $modules,
            array_fill(0, 7, false),
        );

        $segments = [];
        if ($this->showText) {
            $left  = substr($this->digits, 0, 4);
            $right = substr($this->digits, 4, 4);
            // Per ISO 15420 layout (matches the existing drawHumanText positions):
            //   - 4-digit left block: anchor MIDDLE at x=24 (center of padded 10..38, width 28)
            //   - 4-digit right block: anchor MIDDLE at x=57 (center of padded 43..71, width 28)
            $segments[] = new HumanTextSegment($left, 24.0, 0.0, 0.0, TextAnchor::MIDDLE);
            $segments[] = new HumanTextSegment($right, 57.0, 0.0, 0.0, TextAnchor::MIDDLE);
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
            throw new PdfException('Ean8 requires explicit h (height)');
        }
        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $hPt = $unit->toPoints($h);

        Renderer::oriented($page, $this->orientation, $xPt, $yPt, $wPt, $hPt, function () use ($page, $xPt, $yPt, $wPt, $hPt): void {
            // 7 + 67 + 7 = 81 total modules (quiet zones + barcode).
            $moduleW = $wPt / self::TOTAL_MODULES;
            // Standard EAN-8 typography per ISO 15420: left/centre/right guards
            // extend below the data bars and the 4+4 human-readable digits sit
            // centred in that extension band.
            $barsHeight = $this->showText ? $hPt * 0.85 : $hPt;
            $extensionHeight = $hPt - $barsHeight;

            $modules = $this->encodeModules();
            $padded = array_merge(array_fill(0, 7, false), $modules, array_fill(0, 7, false));

            $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);

            if ($extensionHeight > 0.0) {
                // Guard ranges in padded coordinates: left 7..9 (3), centre 38..42 (5), right 71..73 (3).
                foreach ([[7, 3], [38, 5], [71, 3]] as [$start, $len]) {
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

    private function drawHumanText(
        Page $page,
        float $xPt,
        float $yPt,
        float $moduleW,
        float $barsHeight,
        float $extensionHeight,
    ): void {
        $left = substr($this->digits, 0, 4);
        $right = substr($this->digits, 4, 4);

        // Font: each 4-digit group occupies a 28-module zone (~7 modules per digit).
        // Capped at extensionHeight so the glyphs stay inside the extension band.
        $fontSize = min(12.0, 28 * $moduleW / 5.0, $extensionHeight);

        // Baseline vertically centred in the extension band.
        $textY = $yPt + $barsHeight + ($extensionHeight + $fontSize * 0.7) / 2;
        $textYUnit = $page->unit->fromPoints($textY);

        $page->save();
        $fontState = $page->captureFontState();
        $page->setFillColor($this->color);
        $page->setFont(Font::helvetica(), $fontSize);

        // Left half: 4 digits centred in padded 10..37 (28 modules wide).
        $leftStartXUnit = $page->unit->fromPoints($xPt + 10 * $moduleW);
        $halfWidthUnit = $page->unit->fromPoints(28 * $moduleW);
        $leftWidth = $page->stringWidth($left);
        $page->text($leftStartXUnit + ($halfWidthUnit - $leftWidth) / 2, $textYUnit, $left);

        // Right half: 4 digits centred in padded 43..70 (28 modules wide).
        $rightStartXUnit = $page->unit->fromPoints($xPt + 43 * $moduleW);
        $rightWidth = $page->stringWidth($right);
        $page->text($rightStartXUnit + ($halfWidthUnit - $rightWidth) / 2, $textYUnit, $right);

        $page->restoreFontState($fontState);
        $page->restore();
    }
}
