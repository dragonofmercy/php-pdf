<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DOMDocument;
use DOMElement;
use DOMNode;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Svg\Css\CssParser;
use DragonOfMercy\PhpPdf\Svg\Css\CssStylesheet;

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
        'title' => true, 'desc' => true, 'image' => true, 'text' => true,
    ];

    /** @var array<string, DOMElement> */
    private array $defs = [];

    /** @var array<string, DOMElement> */
    private array $gradientDefs = [];

    /** @var array<string, DOMElement> */
    private array $clipPathDefs = [];

    private ?GradientResolver $gradients = null;

    private CssStylesheet $stylesheet;

    /** @var list<Image> */
    private array $embeddedImages = [];

    /** @var array<string, int> contentHash => index in $embeddedImages */
    private array $imageIndexByHash = [];

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
        $this->collectGradientDefs($doc);
        $this->collectClipPaths($doc);
        $this->gradients = new GradientResolver($this->gradientDefs);
        $this->stylesheet = $this->collectStyleSheet($doc);

        $rootAttrs = $this->collectAttrs($root);
        $rootCurrentColor = $this->resolveCurrentColor($rootAttrs, SvgColor::black());
        $rootCss = $this->stylesheet->declarationsFor('svg', $this->classList($rootAttrs), $rootAttrs['id'] ?? null);
        $rootPaint = StyleResolver::resolve(
            SvgPaint::default(),
            $rootAttrs,
            $rootCss,
            $rootAttrs['style'] ?? '',
            $rootCurrentColor,
            $this->gradients,
        );
        $rootText = TextStyleResolver::resolve(
            SvgTextStyle::initial(),
            $rootAttrs,
            $rootCss,
            $rootAttrs['style'] ?? '',
        );

        $children = [];
        foreach ($this->childElements($root) as $child) {
            $node = $this->parseNode($child, $rootPaint, $rootCurrentColor, $rootText, [], 0);
            if ($node !== null) {
                $children[] = $node;
            }
        }

        return new SvgMetadata($viewBox, $aspectRatio, new SvgGroup(null, $children), $this->embeddedImages);
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

    private function collectGradientDefs(DOMDocument $doc): void
    {
        foreach (['linearGradient', 'radialGradient'] as $tag) {
            foreach ($doc->getElementsByTagNameNS(self::SVG_NS, $tag) as $node) {
                if ($node->hasAttribute('id')) {
                    $this->gradientDefs[$node->getAttribute('id')] = $node;
                }
            }
        }
    }

    private function collectClipPaths(DOMDocument $doc): void
    {
        foreach ($doc->getElementsByTagNameNS(self::SVG_NS, 'clipPath') as $node) {
            if ($node->hasAttribute('id')) {
                $this->clipPathDefs[$node->getAttribute('id')] = $node;
            }
        }
    }

    private function collectStyleSheet(DOMDocument $doc): CssStylesheet
    {
        $styleNodes = $doc->getElementsByTagNameNS(self::SVG_NS, 'style');
        if ($styleNodes->length === 0) {
            return CssStylesheet::empty();
        }
        $css = '';
        foreach ($styleNodes as $node) {
            $css .= $node->textContent . "\n";
        }
        return CssParser::parse($css);
    }

    /**
     * @param list<string> $useStack ids currently being resolved (for cycle detection)
     */
    private function parseNode(DOMElement $el, SvgPaint $inherited, SvgColor $currentColor, SvgTextStyle $inheritedText, array $useStack, int $depth, bool $allowClip = true): ?SvgNode
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
        $css = $this->stylesheet->declarationsFor($local, $this->classList($attrs), $attrs['id'] ?? null);
        $paint = StyleResolver::resolve(
            $inherited,
            $attrs,
            $css,
            $attrs['style'] ?? '',
            $newCurrentColor,
            $this->gradients,
        );
        $transform = isset($attrs['transform']) ? TransformParser::parse($attrs['transform']) : null;
        $textStyle = TextStyleResolver::resolve($inheritedText, $attrs, $css, $attrs['style'] ?? '');

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
                    $node = $this->parseNode($child, $paint, $newCurrentColor, $textStyle, $useStack, $depth + 1);
                    if ($node !== null) {
                        $children[] = $node;
                    }
                }
                return $this->wrapClip(new SvgGroup($transform, $children), $allowClip, $attrs, $css);

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
                return $this->wrapClip(new SvgRect($transform, $paint, $x, $y, $w, $h, $rx, $ry), $allowClip, $attrs, $css);

            case 'circle':
                $cx = (float) ($attrs['cx'] ?? 0);
                $cy = (float) ($attrs['cy'] ?? 0);
                $r = (float) ($attrs['r'] ?? 0);
                if ($r <= 0.0) {
                    return null;
                }
                return $this->wrapClip(new SvgCircle($transform, $paint, $cx, $cy, $r), $allowClip, $attrs, $css);

            case 'ellipse':
                $cx = (float) ($attrs['cx'] ?? 0);
                $cy = (float) ($attrs['cy'] ?? 0);
                $rx = (float) ($attrs['rx'] ?? 0);
                $ry = (float) ($attrs['ry'] ?? 0);
                if ($rx <= 0.0 || $ry <= 0.0) {
                    return null;
                }
                return $this->wrapClip(new SvgEllipse($transform, $paint, $cx, $cy, $rx, $ry), $allowClip, $attrs, $css);

            case 'line':
                $x1 = (float) ($attrs['x1'] ?? 0);
                $y1 = (float) ($attrs['y1'] ?? 0);
                $x2 = (float) ($attrs['x2'] ?? 0);
                $y2 = (float) ($attrs['y2'] ?? 0);
                return $this->wrapClip(new SvgLine($transform, $paint, $x1, $y1, $x2, $y2), $allowClip, $attrs, $css);

            case 'polygon':
            case 'polyline':
                $points = $this->parsePoints($attrs['points'] ?? '');
                if ($points === []) {
                    return null;
                }
                return $this->wrapClip(
                    $local === 'polygon'
                        ? new SvgPolygon($transform, $paint, $points)
                        : new SvgPolyline($transform, $paint, $points),
                    $allowClip,
                    $attrs,
                    $css,
                );

            case 'path':
                $d = $attrs['d'] ?? '';
                if ($d === '') {
                    return null;
                }
                $commands = PathDataParser::parse($d);
                if ($commands === []) {
                    return null;
                }
                return $this->wrapClip(new SvgPath($transform, $paint, $commands), $allowClip, $attrs, $css);

            case 'text':
                return $this->wrapClip($this->parseText($el, $paint, $textStyle, $transform), $allowClip, $attrs, $css);

            case 'use':
                return $this->wrapClip($this->resolveUse($el, $attrs, $paint, $newCurrentColor, $textStyle, $transform, $useStack, $depth, $allowClip), $allowClip, $attrs, $css);

            case 'image':
                $w = (float) ($attrs['width'] ?? 0);
                $h = (float) ($attrs['height'] ?? 0);
                if ($w <= 0.0 || $h <= 0.0) {
                    return null;
                }
                $href = $attrs['href'] ?? $attrs['xlink:href'] ?? '';
                $raster = $this->decodeDataUriRaster($href);
                if ($raster === null) {
                    return null;
                }
                $hash = $raster->contentHash;
                if (isset($this->imageIndexByHash[$hash])) {
                    $index = $this->imageIndexByHash[$hash];
                } else {
                    $index = count($this->embeddedImages);
                    $this->embeddedImages[] = $raster;
                    $this->imageIndexByHash[$hash] = $index;
                }
                $x = (float) ($attrs['x'] ?? 0);
                $y = (float) ($attrs['y'] ?? 0);
                $ar = isset($attrs['preserveAspectRatio'])
                    ? PreserveAspectRatio::parse($attrs['preserveAspectRatio'])
                    : PreserveAspectRatio::default();
                return $this->wrapClip(new SvgImage($transform, $x, $y, $w, $h, $ar, $paint->opacity, $index, $raster->width, $raster->height), $allowClip, $attrs, $css);

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
        SvgTextStyle $inheritedText,
        ?SvgMatrix $transform,
        array $useStack,
        int $depth,
        bool $allowClip = true,
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
            $inheritedText,
            [...$useStack, $id],
            $depth + 1,
            $allowClip,
        );
        if ($resolved === null) {
            return null;
        }

        return new SvgGroup($useTransform, [$resolved]);
    }

    /**
     * Flattens a <text> element (mixed text nodes + <tspan> children) into a
     * list of positioned runs. Returns null when no visible text remains.
     */
    private function parseText(DOMElement $el, SvgPaint $paint, SvgTextStyle $style, ?SvgMatrix $transform): ?SvgText
    {
        /** @var list<SvgTextSpan> $spans */
        $spans = [];
        $this->collectTextSpans($el, $paint, $style, $spans);

        if ($spans === []) {
            return null;
        }

        $first = $spans[0];
        if (str_starts_with($first->text, ' ')) {
            $spans[0] = $this->withText($first, ltrim($first->text, ' '));
        }
        $lastIndex = count($spans) - 1;
        $last = $spans[$lastIndex];
        if (str_ends_with($last->text, ' ')) {
            $spans[$lastIndex] = $this->withText($last, rtrim($last->text, ' '));
        }

        $spans = array_values(array_filter($spans, static fn (SvgTextSpan $s): bool => $s->text !== ''));
        if ($spans === []) {
            return null;
        }

        return new SvgText($transform, $spans);
    }

    /**
     * @param list<SvgTextSpan> $spans accumulator
     */
    private function collectTextSpans(DOMElement $el, SvgPaint $inheritedPaint, SvgTextStyle $inheritedStyle, array &$spans): void
    {
        $attrs = $this->collectAttrs($el);
        $currentColor = $this->resolveCurrentColor($attrs, SvgColor::black());
        $css = $this->stylesheet->declarationsFor($el->localName ?? '', $this->classList($attrs), $attrs['id'] ?? null);
        $paint = StyleResolver::resolve($inheritedPaint, $attrs, $css, $attrs['style'] ?? '', $currentColor, $this->gradients);
        $style = TextStyleResolver::resolve($inheritedStyle, $attrs, $css, $attrs['style'] ?? '');

        $font = SvgFontResolver::resolve($style->fontFamily, $style->bold, $style->italic);
        // Gradient/pattern fills on text are not supported; fall back to black.
        $fill = $paint->fill instanceof SvgColor ? $paint->fill : ($paint->fill === null ? null : SvgColor::black());
        $stroke = $paint->stroke instanceof SvgColor ? $paint->stroke : null;

        $x = isset($attrs['x']) ? (float) $attrs['x'] : null;
        $y = isset($attrs['y']) ? (float) $attrs['y'] : null;
        $dx = isset($attrs['dx']) ? (float) $attrs['dx'] : 0.0;
        $dy = isset($attrs['dy']) ? (float) $attrs['dy'] : 0.0;

        $positionPending = true;

        foreach ($el->childNodes ?? [] as $node) {
            if ($node instanceof \DOMText) {
                $text = preg_replace('/[\t\r\n ]+/', ' ', $node->data) ?? '';
                if ($text === '') {
                    continue;
                }
                $spans[] = new SvgTextSpan(
                    text: $text,
                    font: $font,
                    fontSize: $style->fontSize,
                    fill: $fill,
                    fillOpacity: $paint->effectiveFillOpacity(),
                    stroke: $stroke,
                    strokeOpacity: $paint->effectiveStrokeOpacity(),
                    strokeWidth: $paint->strokeWidth,
                    anchor: $style->anchor,
                    x: $positionPending ? $x : null,
                    y: $positionPending ? $y : null,
                    dx: $positionPending ? $dx : 0.0,
                    dy: $positionPending ? $dy : 0.0,
                );
                $positionPending = false;
            } elseif ($node instanceof DOMElement
                && $node->namespaceURI === self::SVG_NS
                && $node->localName === 'tspan') {
                $before = count($spans);
                $this->collectTextSpans($node, $paint, $style, $spans);
                if (count($spans) > $before) {
                    $positionPending = false;
                }
            }
        }
    }

    private function withText(SvgTextSpan $span, string $text): SvgTextSpan
    {
        return new SvgTextSpan(
            text: $text,
            font: $span->font,
            fontSize: $span->fontSize,
            fill: $span->fill,
            fillOpacity: $span->fillOpacity,
            stroke: $span->stroke,
            strokeOpacity: $span->strokeOpacity,
            strokeWidth: $span->strokeWidth,
            anchor: $span->anchor,
            x: $span->x,
            y: $span->y,
            dx: $span->dx,
            dy: $span->dy,
        );
    }

    /**
     * @param array<string, string> $attrs
     * @return list<string>
     */
    private function classList(array $attrs): array
    {
        $class = trim($attrs['class'] ?? '');
        if ($class === '') {
            return [];
        }
        return preg_split('/\s+/', $class) ?: [];
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
     * Decodes a data: URI to a PNG or JPEG Image. Returns null for non-data
     * URIs, non-base64 data URIs, undecodable base64, unparseable rasters, or
     * a decoded SVG (svg+xml data URIs are out of scope). Never touches the
     * network or filesystem.
     */
    private function decodeDataUriRaster(string $href): ?Image
    {
        if (!str_starts_with($href, 'data:')) {
            return null;
        }
        $comma = strpos($href, ',');
        if ($comma === false || stripos(substr($href, 5, $comma - 5), ';base64') === false) {
            return null;
        }
        try {
            // Image::fromBase64 strips the data: prefix and strictly base64-decodes.
            $image = Image::fromBase64($href);
        } catch (PdfException) {
            return null;
        }
        if ($image->format === ImageFormat::SVG) {
            return null;
        }
        return $image;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, string> $css
     */
    private function wrapClip(?SvgNode $node, bool $allowClip, array $attrs, array $css): ?SvgNode
    {
        if ($node === null || !$allowClip) {
            return $node;
        }
        $clip = $this->resolveClip($attrs, $css);
        return $clip !== null ? new SvgClipped($clip, $node) : $node;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, string> $css
     */
    private function resolveClip(array $attrs, array $css): ?SvgClip
    {
        $value = $this->styleProp($attrs['style'] ?? '', 'clip-path')
            ?? ($css['clip-path'] ?? null)
            ?? ($attrs['clip-path'] ?? null);
        if ($value === null) {
            return null;
        }
        $id = $this->clipUrlId($value);
        if ($id === null) {
            return null;
        }
        $el = $this->clipPathDefs[$id] ?? null;
        if ($el === null) {
            return null;
        }

        $units = ClipPathUnits::tryFrom($el->getAttribute('clipPathUnits')) ?? ClipPathUnits::USER_SPACE_ON_USE;
        $transform = $el->hasAttribute('transform') ? TransformParser::parse($el->getAttribute('transform')) : null;

        $nodes = [];
        $clipRule = FillRule::NONZERO;
        $ruleFound = false;
        foreach ($this->childElements($el) as $child) {
            $node = $this->parseNode($child, SvgPaint::default(), SvgColor::black(), SvgTextStyle::initial(), [], 0, allowClip: false);
            if ($node === null) {
                continue;
            }
            $nodes[] = $node;
            if (!$ruleFound) {
                $rule = $this->clipRuleOf($child);
                if ($rule !== null) {
                    $clipRule = $rule;
                    $ruleFound = true;
                }
            }
        }

        return new SvgClip($units, $transform, $nodes, $clipRule);
    }

    private function styleProp(string $style, string $name): ?string
    {
        foreach (explode(';', $style) as $decl) {
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue;
            }
            if (strtolower(trim(substr($decl, 0, $colon))) === $name) {
                $v = trim(substr($decl, $colon + 1));
                return $v !== '' ? $v : null;
            }
        }
        return null;
    }

    private function clipUrlId(string $value): ?string
    {
        $value = trim($value);
        if ($value === 'none' || $value === '') {
            return null;
        }
        if (preg_match('/^url\(\s*#([^)\s]+)\s*\)/i', $value, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    private function clipRuleOf(DOMElement $el): ?FillRule
    {
        $attrs = $this->collectAttrs($el);
        $value = $this->styleProp($attrs['style'] ?? '', 'clip-rule') ?? ($attrs['clip-rule'] ?? null);
        return $value !== null ? FillRule::tryFrom(trim($value)) : null;
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
