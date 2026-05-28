<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Svg\Marker\MarkerResolver;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerSet;
use DragonOfMercy\PhpPdf\Svg\Marker\SvgMarker;

/**
 * Merges presentation attributes, matched stylesheet declarations, and inline
 * style="..." declarations onto an inherited SvgPaint. Precedence: inline style
 * > stylesheet > presentation attribute > inherited.
 * Unknown property names are ignored. Malformed numeric values fall back to
 * SVG defaults silently (per the project's "skip unsupported, fail loudly
 * only on structural errors" stance).
 */
final class StyleResolver
{
    /**
     * @param array<string, string> $presentationAttrs
     * @param array<string, string> $cssDeclarations stylesheet declarations, cascade-collapsed
     */
    public static function resolve(
        SvgPaint $inherited,
        array $presentationAttrs,
        array $cssDeclarations,
        string $styleAttr,
        SvgColor $currentColor,
        ?GradientResolver $gradients = null,
        ?PatternResolver $patterns = null,
        ?MarkerResolver $markers = null,
    ): SvgPaint {
        $merged = $presentationAttrs;
        foreach ($cssDeclarations as $key => $value) {
            $merged[$key] = $value;
        }
        foreach (self::parseStyle($styleAttr) as $key => $value) {
            $merged[$key] = $value;
        }

        $paint = $inherited;
        foreach ($merged as $key => $value) {
            $paint = self::applyOne($paint, $key, $value, $currentColor, $gradients, $patterns, $markers);
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

    private static function applyOne(SvgPaint $paint, string $key, string $value, SvgColor $current, ?GradientResolver $gradients, ?PatternResolver $patterns, ?MarkerResolver $markers): SvgPaint
    {
        switch ($key) {
            case 'fill':
                if ($value === 'none') {
                    return $paint->withFillNone();
                }
                $g = self::resolvePaintRef($value, $current, $gradients, $patterns);
                if ($g !== null) {
                    return $paint->withFill($g);
                }
                if (self::isUnresolvedUrl($value)) {
                    $fallback = self::urlFallbackColor($value, $current);
                    return $fallback !== null ? $paint->withFill($fallback) : $paint;
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
                $g = self::resolvePaintRef($value, $current, $gradients, $patterns);
                if ($g !== null) {
                    return $paint->withStroke($g);
                }
                if (self::isUnresolvedUrl($value)) {
                    $fallback = self::urlFallbackColor($value, $current);
                    return $fallback !== null ? $paint->withStroke($fallback) : $paint;
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

            case 'marker-start':
                $m = self::resolveMarkerRef($value, $current, $markers);
                return $paint->withMarkers(($paint->markers ?? MarkerSet::empty())->withStart($m));

            case 'marker-mid':
                $m = self::resolveMarkerRef($value, $current, $markers);
                return $paint->withMarkers(($paint->markers ?? MarkerSet::empty())->withMid($m));

            case 'marker-end':
                $m = self::resolveMarkerRef($value, $current, $markers);
                return $paint->withMarkers(($paint->markers ?? MarkerSet::empty())->withEnd($m));

            case 'marker':
                $m = self::resolveMarkerRef($value, $current, $markers);
                $set = MarkerSet::empty()->withStart($m)->withMid($m)->withEnd($m);
                return $paint->withMarkers($set);

            default:
                return $paint;
        }
    }

    private static function resolveMarkerRef(string $value, SvgColor $current, ?MarkerResolver $markers): ?SvgMarker
    {
        if ($markers === null) {
            return null;
        }
        $id = self::urlId($value);
        if ($id === null) {
            return null;
        }
        return $markers->resolve($id, $current);
    }

    private static function resolvePaintRef(string $value, SvgColor $current, ?GradientResolver $gradients, ?PatternResolver $patterns): ?SvgPaintSource
    {
        $id = self::urlId($value);
        if ($id === null) {
            return null;
        }
        if ($gradients !== null) {
            $g = $gradients->resolve($id, $current);
            if ($g !== null) {
                return $g;
            }
        }
        if ($patterns !== null) {
            $p = $patterns->resolve($id, $current);
            if ($p !== null) {
                return $p;
            }
        }
        return null;
    }

    private static function isUnresolvedUrl(string $value): bool
    {
        return self::urlId($value) !== null;
    }

    private static function urlId(string $value): ?string
    {
        if (preg_match('/^url\(\s*#([^)\s]+)\s*\)/i', trim($value), $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /** Optional fallback color after url(#id), e.g. "url(#x) red". */
    private static function urlFallbackColor(string $value, SvgColor $current): ?SvgColor
    {
        if (preg_match('/^url\(\s*#[^)\s]+\s*\)\s+(.+)$/i', trim($value), $m) !== 1) {
            return null;
        }
        return ColorParser::parse(trim($m[1]), $current);
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
