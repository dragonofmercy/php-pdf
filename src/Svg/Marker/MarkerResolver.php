<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Marker;

use DOMElement;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\ViewBox;

/**
 * Resolves a marker id (referenced by marker-start/mid/end/marker) into a
 * built SvgMarker: parses children via Parser::parseChildrenAsMarker with the
 * inMarker flag set. Returns null when the id is unknown or yields no children.
 *
 * @internal
 */
final class MarkerResolver
{
    /** @param array<string, DOMElement> $markerDefs */
    public function __construct(
        private array $markerDefs,
        private Parser $parser,
    ) {}

    public function resolve(string $id, SvgColor $currentColor): ?SvgMarker
    {
        $el = $this->markerDefs[$id] ?? null;
        if ($el === null) {
            return null;
        }

        $attrs = [];
        foreach ($el->attributes ?? [] as $a) {
            $attrs[$a->localName ?? $a->name] = $a->value;
        }

        $nodes = $this->parser->parseChildrenAsMarker($el, $currentColor);
        if ($nodes === []) {
            return null;
        }

        $viewBox = isset($attrs['viewBox']) ? $this->parseViewBoxString($attrs['viewBox']) : null;
        $aspectRatio = isset($attrs['preserveAspectRatio'])
            ? PreserveAspectRatio::parse($attrs['preserveAspectRatio'])
            : PreserveAspectRatio::default();

        return new SvgMarker(
            viewBox: $viewBox,
            aspectRatio: $aspectRatio,
            markerWidth: $this->coord($attrs['markerWidth'] ?? null, 3.0),
            markerHeight: $this->coord($attrs['markerHeight'] ?? null, 3.0),
            refX: $this->coord($attrs['refX'] ?? null, 0.0),
            refY: $this->coord($attrs['refY'] ?? null, 0.0),
            units: MarkerUnits::tryFromName($attrs['markerUnits'] ?? null),
            orient: MarkerOrient::parse($attrs['orient'] ?? null),
            nodes: $nodes,
        );
    }

    private function coord(?string $raw, float $default): float
    {
        if ($raw === null) {
            return $default;
        }
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }
        return (float) $raw;
    }

    private function parseViewBoxString(string $raw): ?ViewBox
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        if (count($parts) !== 4) {
            return null;
        }
        $w = (float) $parts[2];
        $h = (float) $parts[3];
        if ($w <= 0.0 || $h <= 0.0) {
            return null;
        }
        return new ViewBox(x: (float) $parts[0], y: (float) $parts[1], width: $w, height: $h);
    }
}
