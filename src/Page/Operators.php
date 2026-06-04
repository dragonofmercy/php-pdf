<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

use DragonOfMercy\PhpPdf\LineCap;
use DragonOfMercy\PhpPdf\LineJoin;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Pure helpers that format PDF content stream operators with correct
 * numeric formatting. Each helper returns the operator line terminated by `\n`.
 *
 * @internal
 */
final class Operators
{
    public static function moveTo(float $x, float $y): string
    {
        return self::num($x) . ' ' . self::num($y) . " m\n";
    }

    public static function lineTo(float $x, float $y): string
    {
        return self::num($x) . ' ' . self::num($y) . " l\n";
    }

    public static function curveTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): string
    {
        return self::num($c1x) . ' ' . self::num($c1y) . ' '
            . self::num($c2x) . ' ' . self::num($c2y) . ' '
            . self::num($x) . ' ' . self::num($y) . " c\n";
    }

    public static function rectangle(float $x, float $y, float $w, float $h): string
    {
        return self::num($x) . ' ' . self::num($y) . ' '
            . self::num($w) . ' ' . self::num($h) . " re\n";
    }

    public static function closePath(): string
    {
        return "h\n";
    }

    public static function stroke(): string
    {
        return "S\n";
    }

    public static function fill(): string
    {
        return "f\n";
    }

    public static function fillStroke(): string
    {
        return "B\n";
    }

    public static function setLineWidth(float $width): string
    {
        return self::num($width) . " w\n";
    }

    public static function setLineCap(LineCap $cap): string
    {
        return $cap->value . " J\n";
    }

    public static function setLineJoin(LineJoin $join): string
    {
        return $join->value . " j\n";
    }

    /**
     * @param list<float> $pattern
     */
    public static function setDashPattern(array $pattern, float $phase): string
    {
        $parts = array_map(self::num(...), $pattern);
        return '[' . implode(' ', $parts) . '] ' . self::num($phase) . " d\n";
    }

    public static function concatMatrix(float $a, float $b, float $c, float $d, float $e, float $f): string
    {
        return self::num($a) . ' ' . self::num($b) . ' ' . self::num($c) . ' '
            . self::num($d) . ' ' . self::num($e) . ' ' . self::num($f) . " cm\n";
    }

    public static function saveState(): string
    {
        return "q\n";
    }

    public static function restoreState(): string
    {
        return "Q\n";
    }

    public static function translate(float $x, float $y): string
    {
        return self::concatMatrix(1, 0, 0, 1, $x, $y);
    }

    public static function scale(float $sx, float $sy): string
    {
        return self::concatMatrix($sx, 0, 0, $sy, 0, 0);
    }

    public static function rotate(float $degrees): string
    {
        $rad = deg2rad($degrees);
        $cos = cos($rad);
        $sin = sin($rad);
        // CW-compensated for Y-down user space (after the Y-flip CTM applied
        // at the start of every ContentStream). Standard PDF CCW is
        // [cos sin -sin cos]; negating the sin terms gives CW.
        return self::concatMatrix($cos, -$sin, $sin, $cos, 0, 0);
    }

    // ----- text operators (Phase 2b) -----

    public static function beginText(): string
    {
        return "BT\n";
    }

    public static function endText(): string
    {
        return "ET\n";
    }

    public static function setFontAndSize(string $shortName, float $size): string
    {
        return '/' . $shortName . ' ' . self::num($size) . " Tf\n";
    }

    public static function setTextLeading(float $leading): string
    {
        return self::num($leading) . " TL\n";
    }

    public static function textMatrix(float $a, float $b, float $c, float $d, float $e, float $f): string
    {
        return self::num($a) . ' ' . self::num($b) . ' ' . self::num($c) . ' '
            . self::num($d) . ' ' . self::num($e) . ' ' . self::num($f) . " Tm\n";
    }

    public static function showText(string $encodedBytes): string
    {
        return PdfString::of($encodedBytes)->toBytes() . " Tj\n";
    }

    public static function showTextNextLine(string $encodedBytes): string
    {
        return PdfString::of($encodedBytes)->toBytes() . " '\n";
    }

    public static function setHorizontalScaling(float $percent): string
    {
        return self::num($percent) . " Tz\n";
    }

    public static function setWordSpacing(float $points): string
    {
        return self::num($points) . " Tw\n";
    }

    public static function invokeXObject(string $shortName): string
    {
        return '/' . $shortName . " Do\n";
    }

    /**
     * Emits a hex-encoded string for showing text with composite (Type0) fonts.
     * The caller provides the hex bytes already formatted (uppercase, padding pair).
     */
    public static function showTextHex(string $hexBytes): string
    {
        return '<' . $hexBytes . "> Tj\n";
    }

    /**
     * Hex variant of `showTextNextLine` (T* + Tj via the apostrophe operator).
     */
    public static function showTextHexNextLine(string $hexBytes): string
    {
        return '<' . $hexBytes . "> '\n";
    }

    /**
     * Emits a TJ array operator. The caller supplies the fully formatted array
     * body (string elements already delimited as ( ) or < >, interleaved with
     * adjustment numbers in thousandths of a text-space unit).
     */
    public static function showTextArray(string $arrayBody): string
    {
        return '[' . $arrayBody . "] TJ\n";
    }

    private static function num(float $value): string
    {
        return PdfNumber::ofFloat($value)->toBytes();
    }
}
