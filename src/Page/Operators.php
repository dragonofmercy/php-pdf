<?php

declare(strict_types=1);

namespace PhpPdf\Page;

use PhpPdf\LineCap;
use PhpPdf\LineJoin;
use PhpPdf\Writer\Object\PdfNumber;

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

    private static function num(float $value): string
    {
        return PdfNumber::ofFloat($value)->toBytes();
    }
}
