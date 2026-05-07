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

        // Module count incl. quiet zone: 11 (left) + 95 + 7 (right) = 113.
        $totalModules = 113;
        $moduleW = $wPt / $totalModules;
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
            $this->drawHumanText($page, $xPt, $yPt, $wPt, $hPt, $moduleW, $barsHeight, $textHeight);
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
        float $wPt,
        float $hPt,
        float $moduleW,
        float $barsHeight,
        float $textHeight,
    ): void {
        $first = $this->digits[0];
        $left = substr($this->digits, 1, 6);
        $right = substr($this->digits, 7, 6);

        // Font + size: Helvetica, sized to fit the half-width comfortably.
        $fontSize = min(12.0, $wPt / 14.0);
        $textY = $yPt + $barsHeight + ($textHeight - $fontSize * 0.7) / 2 + $fontSize * 0.7;
        // Convert text Y back to the page unit so page->text() works.
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

    /** ISO 15420 set A (odd parity) -- 7-bit widths for digits 0-9. */
    private const array SET_A = [
        [false, false, false, true, true, false, true],   // 0 -> 0001101
        [false, false, true, true, false, false, true],   // 1 -> 0011001
        [false, false, true, false, false, true, true],   // 2 -> 0010011
        [false, true, true, true, true, false, true],     // 3 -> 0111101
        [false, true, false, false, false, true, true],   // 4 -> 0100011
        [false, true, true, false, false, false, true],   // 5 -> 0110001
        [false, true, false, true, true, true, true],     // 6 -> 0101111
        [false, true, true, true, false, true, true],     // 7 -> 0111011
        [false, true, true, false, true, true, true],     // 8 -> 0110111
        [false, false, false, true, false, true, true],   // 9 -> 0001011
    ];

    /** ISO 15420 set B (even parity) -- mirror of C read backwards. */
    private const array SET_B = [
        [false, true, false, false, true, true, true],   // 0 -> 0100111
        [false, true, true, false, false, true, true],   // 1 -> 0110011
        [false, false, true, true, false, true, true],   // 2 -> 0011011
        [false, true, false, false, false, false, true], // 3 -> 0100001
        [false, false, true, true, true, false, true],   // 4 -> 0011101
        [false, true, true, true, false, false, true],   // 5 -> 0111001
        [false, false, false, false, true, false, true], // 6 -> 0000101
        [false, false, true, false, false, false, true], // 7 -> 0010001
        [false, false, false, true, false, false, true], // 8 -> 0001001
        [false, false, true, false, true, true, true],   // 9 -> 0010111
    ];

    /** ISO 15420 set C (right side, complement of A). */
    private const array SET_C = [
        [true, true, true, false, false, true, false],   // 0 -> 1110010
        [true, true, false, false, true, true, false],   // 1 -> 1100110
        [true, true, false, true, true, false, false],   // 2 -> 1101100
        [true, false, false, false, false, true, false], // 3 -> 1000010
        [true, false, true, true, true, false, false],   // 4 -> 1011100
        [true, false, false, true, true, true, false],   // 5 -> 1001110
        [true, false, true, false, false, false, false], // 6 -> 1010000
        [true, false, false, false, true, false, false], // 7 -> 1000100
        [true, false, false, true, false, false, false], // 8 -> 1001000
        [true, true, true, false, true, false, false],   // 9 -> 1110100
    ];

    /**
     * Parity pattern for the 6 left-side digits, indexed by the first digit (0-9).
     * 'A' = use SET_A, 'B' = use SET_B. ISO 15420 Table 1.
     */
    private const array LEFT_PARITY = [
        'AAAAAA', // 0
        'AABABB', // 1
        'AABBAB', // 2
        'AABBBA', // 3
        'ABAABB', // 4
        'ABBAAB', // 5
        'ABBBAA', // 6
        'ABABAB', // 7
        'ABABBA', // 8
        'ABBABA', // 9
    ];

    /**
     * Returns the 95-element module sequence for this EAN-13.
     *
     * @internal
     * @return list<bool>
     */
    private function encodeModules(): array
    {
        $first = (int) $this->digits[0];
        $parity = self::LEFT_PARITY[$first];
        $modules = [true, false, true]; // left guard 101
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $this->digits[1 + $i];
            $set = $parity[$i] === 'A' ? self::SET_A : self::SET_B;
            foreach ($set[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        // centre guard 01010
        array_push($modules, false, true, false, true, false);
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $this->digits[7 + $i];
            foreach (self::SET_C[$d] as $bit) {
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
