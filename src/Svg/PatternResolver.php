<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DOMElement;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Resolves a pattern id (referenced by fill="url(#id)") into a fully-built
 * SvgPattern: follows href inheritance, parses children via Parser::parseChildrenAsPattern,
 * normalizes default attributes. Returns null when the id is unknown, the
 * target is not a <pattern>, or the resolved pattern has no children.
 * Throws on href cycles.
 *
 * @internal
 */
final class PatternResolver
{
    private const string XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** @param array<string, DOMElement> $patternDefs */
    public function __construct(
        private array $patternDefs,
        private Parser $parser,
    ) {}

    public function resolve(string $id, SvgColor $currentColor): ?SvgPattern
    {
        if (!isset($this->patternDefs[$id])) {
            return null;
        }
        $attrs = [];
        $childrenEl = null;
        $stack = [];
        $cursor = $id;
        while ($cursor !== null) {
            if (in_array($cursor, $stack, true)) {
                throw new PdfException('Cycle detected in pattern href references: ' . implode(' -> ', [...$stack, $cursor]));
            }
            $stack[] = $cursor;
            $el = $this->patternDefs[$cursor] ?? null;
            if ($el === null) {
                break;
            }
            foreach ($el->attributes ?? [] as $a) {
                $local = $a->localName ?? $a->name;
                if ($local === 'href') {
                    continue;
                }
                if (!array_key_exists($local, $attrs)) {
                    $attrs[$local] = $a->value;
                }
            }
            if ($childrenEl === null && $this->hasRenderableChildren($el)) {
                $childrenEl = $el;
            }
            $cursor = $this->hrefTarget($el);
        }

        $nodes = $childrenEl !== null
            ? $this->parser->parseChildrenAsPattern($childrenEl, $currentColor)
            : [];
        if ($nodes === []) {
            return null;
        }

        return new SvgPattern(
            units: PatternUnits::tryFromName($attrs['patternUnits'] ?? null),
            x: $this->coord($attrs['x'] ?? null, 0.0),
            y: $this->coord($attrs['y'] ?? null, 0.0),
            width: $this->coord($attrs['width'] ?? null, 0.0),
            height: $this->coord($attrs['height'] ?? null, 0.0),
            transform: isset($attrs['patternTransform']) ? TransformParser::parse($attrs['patternTransform']) : null,
            viewBox: $this->parseViewBox($attrs['viewBox'] ?? null),
            nodes: $nodes,
        );
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

    private function hasRenderableChildren(DOMElement $el): bool
    {
        foreach ($el->childNodes ?? [] as $c) {
            if ($c instanceof DOMElement) {
                return true;
            }
        }
        return false;
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

    private function parseViewBox(?string $raw): ?ViewBox
    {
        if ($raw === null) {
            return null;
        }
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        if (count($parts) !== 4) {
            return null;
        }
        $w = (float) $parts[2];
        $h = (float) $parts[3];
        if ($w <= 0.0 || $h <= 0.0) {
            return null;
        }
        return new ViewBox(
            x: (float) $parts[0],
            y: (float) $parts[1],
            width: $w,
            height: $h,
        );
    }
}
