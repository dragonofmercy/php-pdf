<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Merges presentation attributes and inline style="..." declarations onto an
 * inherited SvgPaint. Precedence: inline style > direct attribute > inherited.
 * Unknown property names are ignored. Malformed numeric values fall back to
 * SVG defaults silently (per the project's "skip unsupported, fail loudly
 * only on structural errors" stance).
 */
final class StyleResolver
{
    /**
     * @param array<string, string> $presentationAttrs
     */
    public static function resolve(
        SvgPaint $inherited,
        array $presentationAttrs,
        string $styleAttr,
        SvgColor $currentColor,
    ): SvgPaint {
        $merged = $presentationAttrs;
        foreach (self::parseStyle($styleAttr) as $key => $value) {
            $merged[$key] = $value;
        }

        $paint = $inherited;
        foreach ($merged as $key => $value) {
            $paint = self::applyOne($paint, $key, $value, $currentColor);
        }
        return $paint;
    }

    /**
     * @return array<string, string>
     */
    private static function parseStyle(string $styleAttr): array
    {
        $out = [];
        foreach (explode(';', $styleAttr) as $decl) {
            $decl = trim($decl);
            if ($decl === '') {
                continue;
            }
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

    private static function applyOne(SvgPaint $paint, string $key, string $value, SvgColor $current): SvgPaint
    {
        switch ($key) {
            case 'fill':
                if ($value === 'none') {
                    return $paint->withFillNone();
                }
                $c = ColorParser::parse($value, $current);
                if ($c === null) {
                    return $paint;
                }
                $alpha = ColorParser::parseAlpha($value);
                $next = $paint->withFill($c);
                if ($alpha < 1.0) {
                    $next = $next->withFillOpacity($alpha);
                }
                return $next;

            case 'stroke':
                if ($value === 'none') {
                    return $paint->withStrokeNone();
                }
                $c = ColorParser::parse($value, $current);
                if ($c === null) {
                    return $paint;
                }
                $alpha = ColorParser::parseAlpha($value);
                $next = $paint->withStroke($c);
                if ($alpha < 1.0) {
                    $next = $next->withStrokeOpacity($alpha);
                }
                return $next;

            case 'stroke-width':
                if (!is_numeric($value)) {
                    return $paint;
                }
                return $paint->withStrokeWidth(max(0.0, (float) $value));

            case 'stroke-linecap':
                $cap = StrokeLineCap::tryFrom($value);
                return $cap !== null ? $paint->withStrokeLineCap($cap) : $paint;

            case 'stroke-linejoin':
                $join = StrokeLineJoin::tryFrom($value);
                return $join !== null ? $paint->withStrokeLineJoin($join) : $paint;

            case 'stroke-miterlimit':
                if (!is_numeric($value)) {
                    return $paint;
                }
                return $paint->withStrokeMiterLimit(max(1.0, (float) $value));

            case 'stroke-dasharray':
                if ($value === 'none') {
                    return $paint->withStrokeDashArray([]);
                }
                $parts = preg_split('/[\s,]+/', trim($value)) ?: [];
                $dashes = [];
                foreach ($parts as $p) {
                    if (!is_numeric($p)) {
                        return $paint;
                    }
                    $dashes[] = max(0.0, (float) $p);
                }
                return $paint->withStrokeDashArray($dashes);

            case 'stroke-dashoffset':
                if (!is_numeric($value)) {
                    return $paint;
                }
                return $paint->withStrokeDashOffset((float) $value);

            case 'fill-rule':
                $rule = FillRule::tryFrom($value);
                return $rule !== null ? $paint->withFillRule($rule) : $paint;

            case 'fill-opacity':
                return $paint->withFillOpacity(self::parseAlphaValue($value));

            case 'stroke-opacity':
                return $paint->withStrokeOpacity(self::parseAlphaValue($value));

            case 'opacity':
                return $paint->withOpacity(self::parseAlphaValue($value));

            default:
                return $paint;
        }
    }

    private static function parseAlphaValue(string $value): float
    {
        $v = trim($value);
        if ($v === '') {
            return 1.0;
        }
        if (str_ends_with($v, '%')) {
            return max(0.0, min(1.0, ((float) rtrim($v, '%')) / 100.0));
        }
        if (!is_numeric($v)) {
            return 1.0;
        }
        return max(0.0, min(1.0, (float) $v));
    }
}
