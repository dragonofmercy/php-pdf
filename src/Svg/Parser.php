<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DOMDocument;
use DOMElement;
use DOMNode;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;

final class Parser
{
    public const string SVG_NS = 'http://www.w3.org/2000/svg';
    public const string XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const int MAX_BYTES = 5 * 1024 * 1024;
    private const int MAX_DEPTH = 32;
    private const int MAX_NODES = 50_000;

    private const array WHITELIST = [
        'svg' => true, 'g' => true, 'defs' => true, 'use' => true,
        'path' => true, 'rect' => true, 'circle' => true, 'ellipse' => true,
        'line' => true, 'polygon' => true, 'polyline' => true,
        'title' => true, 'desc' => true,
    ];

    /** @var array<string, DOMElement> */
    private array $defs = [];

    private int $nodeCounter = 0;

    public static function parse(string $xml): SvgMetadata
    {
        return (new self())->doParse($xml);
    }

    private function doParse(string $xml): SvgMetadata
    {
        if (strlen($xml) > self::MAX_BYTES) {
            throw new PdfException('SVG document too large: ' . strlen($xml) . ' bytes (max ' . self::MAX_BYTES . ')');
        }

        $doc = new DOMDocument();
        $useInternal = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);
            if ($loaded === false) {
                $errors = libxml_get_errors();
                $first = $errors[0] ?? null;
                $msg = $first !== null
                    ? sprintf('SVG parse error at line %d column %d: %s', $first->line, $first->column, trim($first->message))
                    : 'SVG parse error';
                throw new PdfException($msg);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternal);
        }

        $root = $doc->documentElement;
        if ($root === null || $root->namespaceURI !== self::SVG_NS || $root->localName !== 'svg') {
            throw new PdfException('Root element is not <svg> in the SVG namespace');
        }

        $viewBox = $this->resolveViewBox($root);
        $aspectRatio = $root->hasAttribute('preserveAspectRatio')
            ? PreserveAspectRatio::parse($root->getAttribute('preserveAspectRatio'))
            : PreserveAspectRatio::default();

        $this->collectDefs($doc);

        $rootCurrentColor = SvgColor::black();
        $rootPaint = SvgPaint::default();

        $children = [];
        foreach ($this->childElements($root) as $child) {
            $node = $this->parseNode($child, $rootPaint, $rootCurrentColor, [], 0);
            if ($node !== null) {
                $children[] = $node;
            }
        }

        return new SvgMetadata($viewBox, $aspectRatio, new SvgGroup(null, $children));
    }

    private function resolveViewBox(DOMElement $root): ViewBox
    {
        if ($root->hasAttribute('viewBox')) {
            return ViewBox::parse($root->getAttribute('viewBox'));
        }
        if ($root->hasAttribute('width') && $root->hasAttribute('height')) {
            $w = (float) $root->getAttribute('width');
            $h = (float) $root->getAttribute('height');
            if ($w > 0.0 && $h > 0.0) {
                return new ViewBox(0.0, 0.0, $w, $h);
            }
        }
        throw new PdfException('Cannot determine SVG intrinsic dimensions: no viewBox and no width/height');
    }

    private function collectDefs(DOMDocument $doc): void
    {
        if ($doc->getElementsByTagNameNS(self::SVG_NS, 'use')->length === 0) {
            return;
        }
        // Any element with an id (anywhere in the tree, not only inside <defs>)
        // can be the target of <use>. Scan the whole document.
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('svg', self::SVG_NS);
        $nodes = $xpath->query('//*[@id]') ?: new \DOMNodeList();
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $this->defs[$node->getAttribute('id')] = $node;
            }
        }
    }

    /**
     * @param list<string> $useStack ids currently being resolved (for cycle detection)
     */
    private function parseNode(DOMElement $el, SvgPaint $inherited, SvgColor $currentColor, array $useStack, int $depth): ?SvgNode
    {
        if (++$this->nodeCounter > self::MAX_NODES) {
            throw new PdfException('SVG node count exceeded (' . self::MAX_NODES . ')');
        }
        if ($depth > self::MAX_DEPTH) {
            throw new PdfException('SVG nesting depth limit exceeded (' . self::MAX_DEPTH . ')');
        }
        if ($el->namespaceURI !== self::SVG_NS) {
            return null;
        }
        $local = $el->localName ?? '';
        if (!isset(self::WHITELIST[$local])) {
            return null;
        }

        $attrs = $this->collectAttrs($el);
        $newCurrentColor = $this->resolveCurrentColor($attrs, $currentColor);
        $paint = StyleResolver::resolve(
            $inherited,
            $attrs,
            $attrs['style'] ?? '',
            $newCurrentColor,
        );
        $transform = isset($attrs['transform']) ? TransformParser::parse($attrs['transform']) : null;

        switch ($local) {
            case 'g':
            case 'svg':
            case 'defs':
                if ($local === 'defs') {
                    // <defs> contents are only reachable via <use>; do not render directly.
                    return null;
                }
                $children = [];
                foreach ($this->childElements($el) as $child) {
                    $node = $this->parseNode($child, $paint, $newCurrentColor, $useStack, $depth + 1);
                    if ($node !== null) {
                        $children[] = $node;
                    }
                }
                return new SvgGroup($transform, $children);

            case 'rect':
                $x = (float) ($attrs['x'] ?? 0);
                $y = (float) ($attrs['y'] ?? 0);
                $w = (float) ($attrs['width'] ?? 0);
                $h = (float) ($attrs['height'] ?? 0);
                if ($w <= 0.0 || $h <= 0.0) {
                    return null;
                }
                $rx = (float) ($attrs['rx'] ?? 0);
                $ry = (float) ($attrs['ry'] ?? 0);
                return new SvgRect($transform, $paint, $x, $y, $w, $h, $rx, $ry);

            case 'circle':
                $cx = (float) ($attrs['cx'] ?? 0);
                $cy = (float) ($attrs['cy'] ?? 0);
                $r = (float) ($attrs['r'] ?? 0);
                if ($r <= 0.0) {
                    return null;
                }
                return new SvgCircle($transform, $paint, $cx, $cy, $r);

            case 'ellipse':
                $cx = (float) ($attrs['cx'] ?? 0);
                $cy = (float) ($attrs['cy'] ?? 0);
                $rx = (float) ($attrs['rx'] ?? 0);
                $ry = (float) ($attrs['ry'] ?? 0);
                if ($rx <= 0.0 || $ry <= 0.0) {
                    return null;
                }
                return new SvgEllipse($transform, $paint, $cx, $cy, $rx, $ry);

            case 'line':
                $x1 = (float) ($attrs['x1'] ?? 0);
                $y1 = (float) ($attrs['y1'] ?? 0);
                $x2 = (float) ($attrs['x2'] ?? 0);
                $y2 = (float) ($attrs['y2'] ?? 0);
                return new SvgLine($transform, $paint, $x1, $y1, $x2, $y2);

            case 'polygon':
            case 'polyline':
                $points = $this->parsePoints($attrs['points'] ?? '');
                if ($points === []) {
                    return null;
                }
                return $local === 'polygon'
                    ? new SvgPolygon($transform, $paint, $points)
                    : new SvgPolyline($transform, $paint, $points);

            case 'path':
                $d = $attrs['d'] ?? '';
                if ($d === '') {
                    return null;
                }
                $commands = PathDataParser::parse($d);
                if ($commands === []) {
                    return null;
                }
                return new SvgPath($transform, $paint, $commands);

            case 'use':
                return $this->resolveUse($el, $attrs, $paint, $newCurrentColor, $transform, $useStack, $depth);

            case 'title':
            case 'desc':
            default:
                return null;
        }
    }

    /**
     * @param array<string, string> $attrs
     * @param list<string> $useStack
     */
    private function resolveUse(
        DOMElement $el,
        array $attrs,
        SvgPaint $paint,
        SvgColor $currentColor,
        ?SvgMatrix $transform,
        array $useStack,
        int $depth,
    ): ?SvgNode {
        $href = $attrs['href'] ?? $attrs['xlink:href'] ?? '';
        if ($href === '' || $href[0] !== '#') {
            return null;
        }
        $id = substr($href, 1);
        if (in_array($id, $useStack, true)) {
            throw new PdfException('Cycle detected in <use> references: ' . implode(' -> ', [...$useStack, $id]));
        }
        $target = $this->defs[$id] ?? null;
        if ($target === null) {
            return null;
        }

        $x = (float) ($attrs['x'] ?? 0);
        $y = (float) ($attrs['y'] ?? 0);
        $useTransform = SvgMatrix::translate($x, $y);
        if ($transform !== null) {
            $useTransform = $transform->compose($useTransform);
        }

        $resolved = $this->parseNode(
            $target,
            $paint,
            $currentColor,
            [...$useStack, $id],
            $depth + 1,
        );
        if ($resolved === null) {
            return null;
        }

        return new SvgGroup($useTransform, [$resolved]);
    }

    /**
     * @return array<string, string>
     */
    private function collectAttrs(DOMElement $el): array
    {
        $out = [];
        foreach ($el->attributes ?? [] as $attr) {
            $local = $attr->localName ?? $attr->name;
            $value = $attr->value;
            if ($attr->namespaceURI === self::XLINK_NS && $local === 'href') {
                $out['xlink:href'] = $value;
            } else {
                $out[$local] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function resolveCurrentColor(array $attrs, SvgColor $inheritedColor): SvgColor
    {
        if (!isset($attrs['color'])) {
            return $inheritedColor;
        }
        $resolved = ColorParser::parse($attrs['color'], $inheritedColor);
        return $resolved ?? $inheritedColor;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function parsePoints(string $value): array
    {
        $parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) % 2 !== 0) {
            array_pop($parts);
        }
        $points = [];
        for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
            $points[] = [(float) $parts[$i], (float) $parts[$i + 1]];
        }
        return $points;
    }

    /**
     * @return list<DOMElement>
     */
    private function childElements(DOMElement $el): array
    {
        $out = [];
        foreach ($el->childNodes ?? [] as $child) {
            if ($child instanceof DOMElement) {
                $out[] = $child;
            }
        }
        return $out;
    }
}
