<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Merges font-* and text-anchor presentation attributes plus inline style=
 * declarations onto an inherited SvgTextStyle. Precedence: inline style >
 * presentation attribute > inherited. Malformed values fall back to the
 * inherited value silently.
 *
 * @internal
 */
final class TextStyleResolver
{
    /**
     * @param array<string, string> $presentationAttrs
     */
    public static function resolve(SvgTextStyle $inherited, array $presentationAttrs, string $styleAttr): SvgTextStyle
    {
        $merged = $presentationAttrs;
        foreach (self::parseStyle($styleAttr) as $key => $value) {
            $merged[$key] = $value;
        }

        $family = $inherited->fontFamily;
        $size = $inherited->fontSize;
        $bold = $inherited->bold;
        $italic = $inherited->italic;
        $anchor = $inherited->anchor;

        if (isset($merged['font-family']) && trim($merged['font-family']) !== '') {
            $family = trim($merged['font-family']);
        }
        if (isset($merged['font-size'])) {
            $size = self::parseFontSize($merged['font-size'], $inherited->fontSize);
        }
        if (isset($merged['font-weight'])) {
            $bold = self::parseWeight($merged['font-weight'], $inherited->bold);
        }
        if (isset($merged['font-style'])) {
            $style = strtolower(trim($merged['font-style']));
            $italic = match ($style) {
                'italic', 'oblique' => true,
                'normal' => false,
                default => $inherited->italic,
            };
        }
        if (isset($merged['text-anchor'])) {
            $anchor = TextAnchor::fromValue($merged['text-anchor']);
        }

        return new SvgTextStyle($family, $size, $bold, $italic, $anchor);
    }

    private static function parseFontSize(string $value, float $inherited): float
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return $inherited;
        }
        if (preg_match('/^(-?\d*\.?\d+)\s*(px|pt|em|%)?$/', $v, $m) !== 1) {
            return $inherited;
        }
        $num = (float) $m[1];
        $unit = $m[2] ?? '';
        $size = match ($unit) {
            'em' => $num * $inherited,
            '%' => $num / 100.0 * $inherited,
            default => $num,
        };
        return $size > 0.0 ? $size : $inherited;
    }

    private static function parseWeight(string $value, bool $inherited): bool
    {
        $v = strtolower(trim($value));
        if (is_numeric($v)) {
            return (float) $v >= 600.0;
        }
        return match ($v) {
            'bold', 'bolder' => true,
            'normal', 'lighter' => false,
            default => $inherited,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function parseStyle(string $styleAttr): array
    {
        $out = [];
        foreach (explode(';', $styleAttr) as $decl) {
            $decl = trim($decl);
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue;
            }
            $key = trim(substr($decl, 0, $colon));
            $value = trim(substr($decl, $colon + 1));
            if ($key !== '' && $value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
