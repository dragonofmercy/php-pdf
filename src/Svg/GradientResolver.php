<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DOMElement;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Resolves a gradient id (referenced by fill="url(#id)") into a fully built
 * SvgGradient: follows href inheritance, parses and normalizes stops, computes
 * uniform opacity. Returns null when the id is unknown, points at a non-gradient,
 * or yields zero stops (callers then apply the black fallback). Throws on href
 * cycles.
 *
 * @internal
 */
final class GradientResolver
{
    private const string XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** @var array<string, ?SvgGradient> memoized by "id|currentColorPacked" */
    private array $cache = [];

    /** @param array<string, DOMElement> $gradientDefs */
    public function __construct(private array $gradientDefs) {}

    public function resolve(string $id, SvgColor $currentColor): ?SvgGradient
    {
        $key = $id . '|' . self::packColor($currentColor);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $result = $this->build($id, $currentColor);
        $this->cache[$key] = $result;
        return $result;
    }

    private function build(string $id, SvgColor $currentColor): ?SvgGradient
    {
        if (!isset($this->gradientDefs[$id])) {
            return null;
        }

        $attrs = [];
        $stopsEl = null;
        $type = null;
        $stack = [];
        $cursor = $id;
        while ($cursor !== null) {
            if (in_array($cursor, $stack, true)) {
                throw new PdfException('Cycle detected in gradient href references: ' . implode(' -> ', [...$stack, $cursor]));
            }
            $stack[] = $cursor;
            $el = $this->gradientDefs[$cursor] ?? null;
            if ($el === null) {
                break;
            }
            $type ??= $el->localName;
            foreach ($el->attributes ?? [] as $a) {
                $local = $a->localName ?? $a->name;
                if ($local === 'href') {
                    continue;
                }
                if (!array_key_exists($local, $attrs)) {
                    $attrs[$local] = $a->value;
                }
            }
            if ($stopsEl === null && $this->hasStopChildren($el)) {
                $stopsEl = $el;
            }
            $cursor = $this->hrefTarget($el);
        }

        if ($type === null) {
            return null;
        }

        $stops = $stopsEl !== null ? $this->parseStops($stopsEl, $currentColor) : [];
        $stops = $this->normalizeStops($stops);
        if ($stops === []) {
            return null;
        }

        $units = ($attrs['gradientUnits'] ?? '') === 'userSpaceOnUse'
            ? GradientUnits::USER_SPACE_ON_USE
            : GradientUnits::OBJECT_BOUNDING_BOX;
        $transform = isset($attrs['gradientTransform'])
            ? TransformParser::parse($attrs['gradientTransform'])
            : null;
        $uniformOpacity = $this->uniformOpacity($stops);
        $spread = SpreadMethod::tryFromName($attrs['spreadMethod'] ?? null);

        if ($type === 'radialGradient') {
            $cx = $this->coord($attrs['cx'] ?? null, 0.5);
            $cy = $this->coord($attrs['cy'] ?? null, 0.5);
            $r = $this->coord($attrs['r'] ?? null, 0.5);
            $fx = $this->coord($attrs['fx'] ?? null, $cx);
            $fy = $this->coord($attrs['fy'] ?? null, $cy);
            return new RadialGradient($cx, $cy, $r, $fx, $fy, $units, $transform, $stops, $uniformOpacity, $spread);
        }

        $x1 = $this->coord($attrs['x1'] ?? null, 0.0);
        $y1 = $this->coord($attrs['y1'] ?? null, 0.0);
        $x2 = $this->coord($attrs['x2'] ?? null, 1.0);
        $y2 = $this->coord($attrs['y2'] ?? null, 0.0);
        return new LinearGradient($x1, $y1, $x2, $y2, $units, $transform, $stops, $uniformOpacity, $spread);
    }

    private function hrefTarget(DOMElement $el): ?string
    {
        $href = $el->getAttributeNS(self::XLINK_NS, 'href');
        if ($href === '') {
            $href = $el->getAttribute('href');
        }
        if ($href === '' || $href[0] !== '#') {
            return null;
        }
        return substr($href, 1);
    }

    private function hasStopChildren(DOMElement $el): bool
    {
        foreach ($el->childNodes ?? [] as $c) {
            if ($c instanceof DOMElement && $c->localName === 'stop') {
                return true;
            }
        }
        return false;
    }

    /** @return list<GradientStop> */
    private function parseStops(DOMElement $el, SvgColor $currentColor): array
    {
        $stops = [];
        foreach ($el->childNodes ?? [] as $c) {
            if (!$c instanceof DOMElement || $c->localName !== 'stop') {
                continue;
            }
            $style = $this->parseStyle($c->getAttribute('style'));
            $offset = $this->parseOffset($c->getAttribute('offset'));
            $colorRaw = $style['stop-color'] ?? ($c->hasAttribute('stop-color') ? $c->getAttribute('stop-color') : 'black');
            $color = ColorParser::parse($colorRaw, $currentColor) ?? SvgColor::black();
            $opacityRaw = $style['stop-opacity'] ?? ($c->hasAttribute('stop-opacity') ? $c->getAttribute('stop-opacity') : '1');
            $opacity = $this->parseOpacity($opacityRaw);
            $stops[] = new GradientStop($offset, $color, $opacity);
        }
        return $stops;
    }

    private function parseOffset(string $raw): float
    {
        $raw = trim($raw);
        if (str_ends_with($raw, '%')) {
            return max(0.0, min(1.0, ((float) rtrim($raw, '%')) / 100.0));
        }
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }
        return max(0.0, min(1.0, (float) $raw));
    }

    private function parseOpacity(string $raw): float
    {
        $raw = trim($raw);
        if (str_ends_with($raw, '%')) {
            return max(0.0, min(1.0, ((float) rtrim($raw, '%')) / 100.0));
        }
        if ($raw === '' || !is_numeric($raw)) {
            return 1.0;
        }
        return max(0.0, min(1.0, (float) $raw));
    }

    private function coord(?string $raw, float $default): float
    {
        if ($raw === null) {
            return $default;
        }
        $raw = trim($raw);
        if (str_ends_with($raw, '%')) {
            return ((float) rtrim($raw, '%')) / 100.0;
        }
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }
        return (float) $raw;
    }

    /**
     * @param list<GradientStop> $stops
     * @return list<GradientStop>
     */
    private function normalizeStops(array $stops): array
    {
        if ($stops === []) {
            return [];
        }
        // Single stop: replicate at 0.0 and 1.0, ignoring its offset.
        if (count($stops) === 1) {
            $s = $stops[0];
            return [
                new GradientStop(0.0, $s->color, $s->opacity),
                new GradientStop(1.0, $s->color, $s->opacity),
            ];
        }
        $prev = 0.0;
        $mono = [];
        foreach ($stops as $i => $s) {
            $off = $i === 0 ? $s->offset : max($prev, $s->offset);
            $mono[] = new GradientStop($off, $s->color, $s->opacity);
            $prev = $off;
        }
        $first = $mono[0];
        if ($first->offset > 0.0) {
            array_unshift($mono, new GradientStop(0.0, $first->color, $first->opacity));
        }
        $last = $mono[count($mono) - 1];
        if ($last->offset < 1.0) {
            $mono[] = new GradientStop(1.0, $last->color, $last->opacity);
        }
        return $mono;
    }

    /** @param list<GradientStop> $stops */
    private function uniformOpacity(array $stops): float
    {
        $first = $stops[0]->opacity;
        foreach ($stops as $s) {
            if ($s->opacity !== $first) {
                return 1.0;
            }
        }
        return $first;
    }

    /** @return array<string, string> */
    private function parseStyle(string $style): array
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue;
            }
            $k = trim(substr($decl, 0, $colon));
            $v = trim(substr($decl, $colon + 1));
            if ($k !== '' && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function packColor(SvgColor $c): string
    {
        return sprintf('%.4f,%.4f,%.4f', $c->r, $c->g, $c->b);
    }
}
