<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Parses an SVG `transform` attribute value into a single composed
 * `SvgMatrix`. Supported functions: matrix, translate, scale, rotate,
 * skewX, skewY. Functions are applied left-to-right (mathematically:
 * `result = T1 * T2 * T3` for "T1 T2 T3").
 */
final class TransformParser
{
    private const string FUNCTION_RX = '/([A-Za-z]+)\s*\(([^)]*)\)/A';

    public static function parse(string $attr): ?SvgMatrix
    {
        $attr = trim($attr);
        if ($attr === '') {
            return null;
        }

        $result = null;
        $pos = 0;
        $len = strlen($attr);

        while ($pos < $len) {
            // Skip leading whitespace + commas between functions.
            while ($pos < $len && (ctype_space($attr[$pos]) || $attr[$pos] === ',')) {
                $pos++;
            }
            if ($pos >= $len) {
                break;
            }

            if (preg_match(self::FUNCTION_RX, $attr, $m, 0, $pos) !== 1) {
                throw new PdfException("Malformed transform attribute: '" . substr($attr, $pos) . "'");
            }

            $name = $m[1];
            $args = self::parseArgs($m[2]);
            $matrix = self::buildMatrix($name, $args);

            if ($matrix === null) {
                // Unknown function -> truncate silently.
                break;
            }

            $result = $result === null ? $matrix : $result->compose($matrix);
            $pos += strlen($m[0]);
        }

        return $result;
    }

    /**
     * @return list<float>
     */
    private static function parseArgs(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $body) ?: [];
        return array_map(static fn (string $p): float => (float) $p, $parts);
    }

    /**
     * @param list<float> $args
     */
    private static function buildMatrix(string $name, array $args): ?SvgMatrix
    {
        return match ($name) {
            'matrix' => self::matrixFn($args),
            'translate' => self::translateFn($args),
            'scale' => self::scaleFn($args),
            'rotate' => self::rotateFn($args),
            'skewX' => self::skewXFn($args),
            'skewY' => self::skewYFn($args),
            default => null,
        };
    }

    /**
     * @param list<float> $a
     */
    private static function matrixFn(array $a): SvgMatrix
    {
        if (count($a) !== 6) {
            throw new PdfException('matrix() expects 6 numbers, got ' . count($a));
        }
        return new SvgMatrix($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]);
    }

    /**
     * @param list<float> $a
     */
    private static function translateFn(array $a): SvgMatrix
    {
        $n = count($a);
        if ($n === 1) {
            return SvgMatrix::translate($a[0], 0.0);
        }
        if ($n === 2) {
            return SvgMatrix::translate($a[0], $a[1]);
        }
        throw new PdfException('translate() expects 1 or 2 numbers, got ' . $n);
    }

    /**
     * @param list<float> $a
     */
    private static function scaleFn(array $a): SvgMatrix
    {
        $n = count($a);
        if ($n === 1) {
            return SvgMatrix::scale($a[0]);
        }
        if ($n === 2) {
            return SvgMatrix::scale($a[0], $a[1]);
        }
        throw new PdfException('scale() expects 1 or 2 numbers, got ' . $n);
    }

    /**
     * @param list<float> $a
     */
    private static function rotateFn(array $a): SvgMatrix
    {
        $n = count($a);
        if ($n === 1) {
            return SvgMatrix::rotate($a[0]);
        }
        if ($n === 3) {
            return SvgMatrix::rotate($a[0], $a[1], $a[2]);
        }
        throw new PdfException('rotate() expects 1 or 3 numbers, got ' . $n);
    }

    /**
     * @param list<float> $a
     */
    private static function skewXFn(array $a): SvgMatrix
    {
        if (count($a) !== 1) {
            throw new PdfException('skewX() expects 1 number, got ' . count($a));
        }
        return SvgMatrix::skewX($a[0]);
    }

    /**
     * @param list<float> $a
     */
    private static function skewYFn(array $a): SvgMatrix
    {
        if (count($a) !== 1) {
            throw new PdfException('skewY() expects 1 number, got ' . count($a));
        }
        return SvgMatrix::skewY($a[0]);
    }
}
