<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Mask;

use DOMElement;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgColor;

/**
 * Resolves a mask id (referenced by mask="url(#id)") into a built SvgMask.
 * Parses children via Parser::parseChildrenAsMask with the inMask flag set
 * (which silences nested url(#mask) refs inside the mask itself).
 *
 * Returns null when the id is unknown or yields no children. No memoization:
 * the resolver is called once per masked-element occurrence (mirror of
 * MarkerResolver, PatternResolver).
 *
 * @internal
 */
final class MaskResolver
{
    /** @param array<string, DOMElement> $maskDefs */
    public function __construct(
        private array  $maskDefs,
        private Parser $parser,
    ) {}

    public function resolve(string $id, SvgColor $currentColor): ?SvgMask
    {
        $el = $this->maskDefs[$id] ?? null;
        if ($el === null) {
            return null;
        }

        $attrs = [];
        foreach ($el->attributes ?? [] as $a) {
            $attrs[$a->localName ?? $a->name] = $a->value;
        }

        $nodes = $this->parser->parseChildrenAsMask($el, $currentColor);
        if ($nodes === []) {
            return null;
        }

        $units = MaskUnits::tryFromName($attrs['maskUnits'] ?? null);
        $contentUnits = MaskUnits::tryFromContentName($attrs['maskContentUnits'] ?? null);

        return new SvgMask(
            id: $id,
            units: $units,
            contentUnits: $contentUnits,
            x: $this->coord($attrs['x'] ?? null, -0.1),
            y: $this->coord($attrs['y'] ?? null, -0.1),
            width: $this->coord($attrs['width'] ?? null, 1.2),
            height: $this->coord($attrs['height'] ?? null, 1.2),
            nodes: $nodes,
        );
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
}
