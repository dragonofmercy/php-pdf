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
final readonly class Ean13 implements Barcode
{
    /** Total module count including quiet zones: 11 (left quiet) + 95 (bars) + 7 (right quiet). */
    private const int TOTAL_MODULES = 113;

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
        if ($h === null) {
            throw new PdfException('Ean13 requires explicit h (height)');
        }

        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $hPt = $unit->toPoints($h);

        $totalModules = self::TOTAL_MODULES;
        $moduleW = $wPt / $totalModules;
        // 85% of h goes to the bars, 15% to the human-readable text below.
        // This is a layout choice (not an ISO value); good visual balance for typical
        // EAN-13 sizes. If you want bars-only, use ->withoutText() instead of tweaking this.
        $barsHeight = $hPt * 0.85;
        $textHeight = $hPt - $barsHeight;

        $modules = $this->encodeModules();
        // Pad with leading false for left quiet zone, trailing false for right.
        $padded = array_merge(
            array_fill(0, 11, false),
            $modules,
            array_fill(0, 7, false),
        );

        $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);

        $contentStream = $page->contentStream();
        $contentStream->append(Renderer::wrap($body, $this->color));

        if ($this->showText) {
            $this->drawHumanText($page, $xPt, $yPt, $hPt, $moduleW, $barsHeight, $textHeight);
        }
    }

    /**
     * Draws the EAN-13 human-readable digits in the official layout:
     *   - first digit detached to the left, inside the quiet zone
     *   - 6 digits centred under the left half (between left guard and centre)
     *   - 6 digits centred under the right half (between centre and right guard)
     */
    private function drawHumanText(
        Page $page,
        float $xPt,
        float $yPt,
        float $hPt,
        float $moduleW,
        float $barsHeight,
        float $textHeight,
    ): void {
        $first = $this->digits[0];
        $left = substr($this->digits, 1, 6);
        $right = substr($this->digits, 7, 6);

        // Font + size: Helvetica, sized to fit the half-width comfortably.
        $fontSize = min(12.0, (self::TOTAL_MODULES * $moduleW) / 14.0);
        // Baseline for the human-readable digits: centre of the text band, raised by
        // half the cap-height (approximated as fontSize * 0.7 / 2) so the glyphs sit
        // vertically centred in the reserved 15% strip.
        $textY = $yPt + $barsHeight + ($textHeight - $fontSize * 0.7) / 2 + $fontSize * 0.7;
        $textYUnit = $page->unit->fromPoints($textY);

        $page->save();
        $page->setFillColor($this->color);
        $page->setFont(Font::helvetica(), $fontSize);

        // First digit: x ~= xPt + 1 module (in unit).
        $firstX = $page->unit->fromPoints($xPt + $moduleW);
        $page->text($firstX, $textYUnit, $first);

        // Left half (between modules 14 and 47 in padded coords): 6 digits centred.
        // module index 14 = end of left guard, 47 = start of centre. Width = 33 modules.
        $leftStartX = $page->unit->fromPoints($xPt + 14 * $moduleW);
        $leftHalfWidth = 33 * $moduleW;
        $leftWidth = $page->stringWidth($left); // in unit
        $leftX = $leftStartX + ($page->unit->fromPoints($leftHalfWidth) - $leftWidth) / 2;
        $page->text($leftX, $textYUnit, $left);

        // Right half: module index 52 = end of centre, 85 = start of right guard. Width = 33.
        $rightStartX = $page->unit->fromPoints($xPt + 52 * $moduleW);
        $rightWidth = $page->stringWidth($right);
        $rightX = $rightStartX + ($page->unit->fromPoints($leftHalfWidth) - $rightWidth) / 2;
        $page->text($rightX, $textYUnit, $right);

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
