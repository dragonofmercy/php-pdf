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
final readonly class Upca implements OrientableBarcode, SizedBarcode
{
    use Orientable;
    /** 9 (left quiet) + 95 (bars) + 9 (right quiet). */
    private const int TOTAL_MODULES = 113;
    private const int QUIET = 9;

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

    public function encode(): EncodedBarcode
    {
        $modules = $this->encodeModules();
        $padded = array_merge(
            array_fill(0, self::QUIET, false),
            $modules,
            array_fill(0, self::QUIET, false),
        );

        $segments = [];
        if ($this->showText) {
            $first       = $this->digits[0];
            $middleLeft  = substr($this->digits, 1, 5);
            $middleRight = substr($this->digits, 6, 5);
            $last        = $this->digits[11];
            // Per ISO 15420 UPC-A layout (matches the existing drawHumanText positions):
            //   - number-system digit: anchor START at x=1 (inside left quiet zone)
            //   - 5-digit middle-left block: anchor MIDDLE at x=36.5 (center of padded 19..54, width 35)
            //   - 5-digit middle-right block: anchor MIDDLE at x=76.5 (center of padded 59..94, width 35)
            //   - check digit: anchor START at x=105 (inside right quiet zone)
            $segments[] = new HumanTextSegment($first, 1.0, 0.0, 0.0, TextAnchor::START);
            $segments[] = new HumanTextSegment($middleLeft, 36.5, 0.0, 0.0, TextAnchor::MIDDLE);
            $segments[] = new HumanTextSegment($middleRight, 76.5, 0.0, 0.0, TextAnchor::MIDDLE);
            $segments[] = new HumanTextSegment($last, 105.0, 0.0, 0.0, TextAnchor::START);
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
            throw new PdfException('Upca requires explicit h (height)');
        }
        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $hPt = $unit->toPoints($h);

        Renderer::oriented($page, $this->orientation, $xPt, $yPt, $wPt, $hPt, function () use ($page, $xPt, $yPt, $wPt, $hPt): void {
            $moduleW = $wPt / self::TOTAL_MODULES;
            // Standard UPC-A typography per ISO 15420: five zones extend below the
            // data bars (left guard, number-system digit bars, centre guard, check
            // digit bars, right guard). The 5+5 middle digits sit centred in the
            // extension band between those tabs; number-system and check digits are
            // detached to the left and right respectively.
            $barsHeight = $this->showText ? $hPt * 0.85 : $hPt;
            $extensionHeight = $hPt - $barsHeight;

            $modules = $this->encodeModules();
            $padded = array_merge(
                array_fill(0, self::QUIET, false),
                $modules,
                array_fill(0, self::QUIET, false),
            );

            $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);

            if ($extensionHeight > 0.0) {
                // Extension ranges in padded coordinates:
                //   left guard         -> 9..11   (3 modules)
                //   number-system bars -> 12..18  (7 modules, encodes digit 0)
                //   centre guard       -> 54..58  (5 modules)
                //   check-digit bars   -> 94..100 (7 modules, encodes digit 11)
                //   right guard        -> 101..103 (3 modules)
                foreach ([[9, 3], [12, 7], [54, 5], [94, 7], [101, 3]] as [$start, $len]) {
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
     * Draws the UPC-A human-readable digits in the official layout:
     *   - number-system digit detached to the left (inside left quiet zone)
     *   - 5 digits centred in padded 19..53 (35 modules, between number-system bars and centre guard)
     *   - 5 digits centred in padded 59..93 (35 modules, between centre guard and check-digit bars)
     *   - check digit detached to the right (inside right quiet zone)
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
        $middleLeft = substr($this->digits, 1, 5);
        $middleRight = substr($this->digits, 6, 5);
        $last = $this->digits[11];

        // Font: each 5-digit group occupies a 35-module zone (~7 modules per digit).
        // Capped at extensionHeight so the glyphs stay inside the extension band.
        $fontSize = min(12.0, 35 * $moduleW / 6.0, $extensionHeight);

        // Baseline vertically centred in the extension band.
        $textY = $yPt + $barsHeight + ($extensionHeight + $fontSize * 0.7) / 2;
        $textYUnit = $page->unit->fromPoints($textY);

        $page->save();
        $fontState = $page->captureFontState();
        $page->setFillColor($this->color);
        $page->setFont(Font::helvetica(), $fontSize);

        // Number-system digit: 1 module from the left edge of the quiet zone.
        $firstX = $page->unit->fromPoints($xPt + $moduleW);
        $page->text($firstX, $textYUnit, $first);

        // Middle-left 5 digits: centred in padded 19..53 (35 modules wide).
        $leftStartX = $page->unit->fromPoints($xPt + 19 * $moduleW);
        $halfWidthUnit = $page->unit->fromPoints(35 * $moduleW);
        $leftW = $page->stringWidth($middleLeft);
        $page->text($leftStartX + ($halfWidthUnit - $leftW) / 2, $textYUnit, $middleLeft);

        // Middle-right 5 digits: centred in padded 59..93 (35 modules wide).
        $rightStartX = $page->unit->fromPoints($xPt + 59 * $moduleW);
        $rightW = $page->stringWidth($middleRight);
        $page->text($rightStartX + ($halfWidthUnit - $rightW) / 2, $textYUnit, $middleRight);

        // Check digit: 1 module right of the right guard.
        $lastX = $page->unit->fromPoints($xPt + 105 * $moduleW);
        $page->text($lastX, $textYUnit, $last);

        $page->restoreFontState($fontState);
        $page->restore();
    }
}
