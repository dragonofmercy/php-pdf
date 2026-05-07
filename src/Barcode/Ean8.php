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
final readonly class Ean8 implements Barcode
{
    private function __construct(
        public string $digits,
        public Color $color,
        public bool $showText,
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
        return new self($this->digits, $color, $this->showText);
    }

    public function withoutText(): self
    {
        return new self($this->digits, $this->color, false);
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
        $setA = Ean13::SET_A_FOR_EAN8();
        $setC = Ean13::SET_C_FOR_EAN8();
        for ($i = 0; $i < 4; $i++) {
            $d = (int) $this->digits[$i];
            foreach ($setA[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        array_push($modules, false, true, false, true, false); // centre guard 01010
        for ($i = 0; $i < 4; $i++) {
            $d = (int) $this->digits[4 + $i];
            foreach ($setC[$d] as $bit) {
                $modules[] = $bit;
            }
        }
        array_push($modules, true, false, true); // right guard 101
        return $modules;
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

        // 7 + 67 + 7 = 81 total modules (quiet zones + barcode).
        $totalModules = 81;
        $moduleW = $wPt / $totalModules;
        // 85% of h goes to the bars, 15% to the human-readable text below.
        $barsHeight = $hPt * 0.85;
        $textHeight = $hPt - $barsHeight;

        $modules = $this->encodeModules();
        $padded = array_merge(array_fill(0, 7, false), $modules, array_fill(0, 7, false));

        $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);
        $page->contentStream()->append(Renderer::wrap($body, $this->color));

        if ($this->showText) {
            $this->drawHumanText($page, $xPt, $yPt, $moduleW, $barsHeight, $textHeight);
        }
    }

    private function drawHumanText(
        Page $page,
        float $xPt,
        float $yPt,
        float $moduleW,
        float $barsHeight,
        float $textHeight,
    ): void {
        $left = substr($this->digits, 0, 4);
        $right = substr($this->digits, 4, 4);

        // Font sized to fit the half-width comfortably.
        $fontSize = min(12.0, 81 * $moduleW / 10.0);
        // Baseline: centre of the text band, raised by half the cap-height.
        $textY = $yPt + $barsHeight + ($textHeight - $fontSize * 0.7) / 2 + $fontSize * 0.7;
        $textYUnit = $page->unit->fromPoints($textY);

        $page->save();
        $page->setFillColor($this->color);
        $page->setFont(Font::helvetica(), $fontSize);

        // Left half: 4 digits centred over modules 10..37 in padded coords (7 quiet + 3 guard = 10 start, width 28).
        $leftStartXUnit = $page->unit->fromPoints($xPt + 10 * $moduleW);
        $leftHalfWidthUnit = $page->unit->fromPoints(28 * $moduleW);
        $leftWidth = $page->stringWidth($left);
        $leftX = $leftStartXUnit + ($leftHalfWidthUnit - $leftWidth) / 2;
        $page->text($leftX, $textYUnit, $left);

        // Right half: modules 43..70 in padded coords (7+3+28+5 = 43 start, width 28).
        $rightStartXUnit = $page->unit->fromPoints($xPt + 43 * $moduleW);
        $rightWidth = $page->stringWidth($right);
        $rightX = $rightStartXUnit + ($leftHalfWidthUnit - $rightWidth) / 2;
        $page->text($rightX, $textYUnit, $right);

        $page->restore();
    }
}
