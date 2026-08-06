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
use DragonOfMercy\PhpPdf\Svg\Filter\BlendMode;
use DragonOfMercy\PhpPdf\Svg\Filter\ColorMatrixType;
use DragonOfMercy\PhpPdf\Svg\Filter\CompositeOperator;
use DragonOfMercy\PhpPdf\Svg\Filter\FeBlend;
use DragonOfMercy\PhpPdf\Svg\Filter\FeColorMatrix;
use DragonOfMercy\PhpPdf\Svg\Filter\FeComposite;
use DragonOfMercy\PhpPdf\Svg\Filter\FeDropShadow;
use DragonOfMercy\PhpPdf\Svg\Filter\FeFlood;
use DragonOfMercy\PhpPdf\Svg\Filter\FeGaussianBlur;
use DragonOfMercy\PhpPdf\Svg\Filter\FeMerge;
use DragonOfMercy\PhpPdf\Svg\Filter\FeOffset;
use DragonOfMercy\PhpPdf\Svg\Filter\FilterPrimitive;
use DragonOfMercy\PhpPdf\Svg\Filter\FilterUnits;
use DragonOfMercy\PhpPdf\Svg\Filter\Subregion;
use DragonOfMercy\PhpPdf\Svg\Filter\SvgFilter;
use DragonOfMercy\PhpPdf\Svg\Mask\MaskResolver;

final class Parser
{
    public const string SVG_NS = 'http://www.w3.org/2000/svg';
    public const string XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const int MAX_BYTES = 5 * 1024 * 1024;
    private const int MAX_DEPTH = 32;
    private const int MAX_NODES = 50_000;

    private const array WHITELIST = [
        'svg' => true, 'g' => true, 'defs' => true, 'use' => true,
        'symbol' => true, 'marker' => true, 'mask' => true, 'filter' => true,
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

    /** @var array<string, SvgFilter> */
    private array $filters = [];

    private ?GradientResolver $gradients = null;

    private ?PatternResolver $patterns = null;

    private ?Marker\MarkerResolver $markers = null;

    private ?MaskResolver $masks = null;

    private CssStylesheet $stylesheet;

    /** @var list<Image> */
    private array $embeddedImages = [];

    /** @var array<string, int> contentHash => index in $embeddedImages */
    private array $imageIndexByHash = [];

    private int $nodeCounter = 0;

    private bool $inPattern = false;

    private bool $inMarker = false;

    private bool $inMask = false;

    public function __construct()
    {
        $this->stylesheet = CssStylesheet::empty();
    }

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
        $this->collectFilters($doc);
        $this->gradients = new GradientResolver($this->gradientDefs);
        $this->patterns = new PatternResolver($this->collectPatternDefs($doc), $this);
        $this->markers = new Marker\MarkerResolver($this->collectMarkerDefs($doc), $this);
        $this->masks = new MaskResolver($this->collectMaskDefs($doc), $this);
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
            $this->inPattern || $this->inMarker ? null : $this->gradients,
            $this->inPattern || $this->inMarker ? null : $this->patterns,
            $this->inPattern || $this->inMarker ? null : $this->markers,
            $this->inPattern || $this->inMarker || $this->inMask ? null : $this->masks,
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
        $needsDefs = $doc->getElementsByTagNameNS(self::SVG_NS, 'use')->length > 0
            || $doc->getElementsByTagNameNS(self::SVG_NS, 'textPath')->length > 0;
        if (!$needsDefs) {
            return;
        }
        // Any element with an id (anywhere in the tree, not only inside <defs>)
        // can be the target of <use> or <textPath>. Scan the whole document.
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

    /**
     * Indexes all <pattern> elements by id for PatternResolver construction.
     * Called only by doParse(); mirrors the visibility of collectGradientDefs.
     *
     * @return array<string, DOMElement>
     */
    private function collectPatternDefs(DOMDocument $doc): array
    {
        $map = [];
        foreach ($doc->getElementsByTagNameNS(self::SVG_NS, 'pattern') as $node) {
            if ($node->hasAttribute('id')) {
                $map[$node->getAttribute('id')] = $node;
            }
        }
        return $map;
    }

    /**
     * Indexes all <marker> elements by id for MarkerResolver construction.
     *
     * @return array<string, DOMElement>
     */
    private function collectMarkerDefs(DOMDocument $doc): array
    {
        $map = [];
        foreach ($doc->getElementsByTagNameNS(self::SVG_NS, 'marker') as $el) {
            if ($el->hasAttribute('id')) {
                $map[$el->getAttribute('id')] = $el;
            }
        }
        return $map;
    }

    /**
     * @return array<string, DOMElement>
     */
    private function collectMaskDefs(DOMDocument $doc): array
    {
        $map = [];
        foreach ($doc->getElementsByTagNameNS(self::SVG_NS, 'mask') as $el) {
            if ($el->hasAttribute('id')) {
                $map[$el->getAttribute('id')] = $el;
            }
        }
        return $map;
    }

    private function collectClipPaths(DOMDocument $doc): void
    {
        foreach ($doc->getElementsByTagNameNS(self::SVG_NS, 'clipPath') as $node) {
            if ($node->hasAttribute('id')) {
                $this->clipPathDefs[$node->getAttribute('id')] = $node;
            }
        }
    }

    /**
     * Indexes all <filter> elements by id into $this->filters as parsed
     * SvgFilter value objects (region + ordered primitive list).
     */
    private function collectFilters(DOMDocument $doc): void
    {
        foreach ($doc->getElementsByTagNameNS(self::SVG_NS, 'filter') as $el) {
            if ($el->hasAttribute('id')) {
                $this->filters[$el->getAttribute('id')] = $this->parseFilter($el);
            }
        }
    }

    /**
     * Parses a <filter> element. Region defaults are the SVG spec defaults
     * (x=-10%, y=-10%, width=120%, height=120%).
     *
     * Region coordinate convention: a percentage is stored as a FRACTION
     * ("-20%" -> -0.2) and a plain length is stored as its numeric value
     * ("10" -> 10.0). The renderer interprets these per filterUnits
     * (objectBoundingBox multiplies the fraction by the bbox; userSpaceOnUse
     * uses the length directly).
     */
    private function parseFilter(DOMElement $el): SvgFilter
    {
        $id = $el->hasAttribute('id') ? $el->getAttribute('id') : null;
        $filterUnits = FilterUnits::fromString($el->getAttribute('filterUnits'), FilterUnits::OBJECT_BOUNDING_BOX);
        $primitiveUnits = FilterUnits::fromString($el->getAttribute('primitiveUnits'), FilterUnits::USER_SPACE_ON_USE);

        $x = $el->hasAttribute('x') ? $this->regionLength($el->getAttribute('x')) : -0.1;
        $y = $el->hasAttribute('y') ? $this->regionLength($el->getAttribute('y')) : -0.1;
        $width = $el->hasAttribute('width') ? $this->regionLength($el->getAttribute('width')) : 1.2;
        $height = $el->hasAttribute('height') ? $this->regionLength($el->getAttribute('height')) : 1.2;

        $primitives = [];
        foreach ($this->childElements($el) as $child) {
            if ($child->namespaceURI !== self::SVG_NS) {
                continue;
            }
            $primitive = $this->parseFilterPrimitive($child);
            if ($primitive !== null) {
                $primitives[] = $primitive;
            }
        }

        return new SvgFilter($id, $filterUnits, $primitiveUnits, $x, $y, $width, $height, $primitives);
    }

    /**
     * Parses a single value as a region length: percentages become fractions
     * ("-20%" -> -0.2), plain lengths keep their numeric value.
     */
    private function regionLength(string $raw): float
    {
        $raw = trim($raw);
        if (str_ends_with($raw, '%')) {
            return (float) substr($raw, 0, -1) / 100.0;
        }
        return (float) $raw;
    }

    /**
     * Parses one filter primitive element (fe*) into its VO. Returns null for
     * unknown fe* elements and for any non-primitive child (e.g. feMergeNode,
     * which is consumed by feMerge).
     */
    private function parseFilterPrimitive(DOMElement $el): ?FilterPrimitive
    {
        $attrs = $this->collectAttrs($el);
        $in = $attrs['in'] ?? null;
        $in2 = $attrs['in2'] ?? null;
        $result = $attrs['result'] ?? null;
        $subregion = $this->parseSubregion($attrs);

        switch ($el->localName) {
            case 'feGaussianBlur':
                [$sx, $sy] = $this->parseStdDeviation($attrs['stdDeviation'] ?? '');
                return new FeGaussianBlur($in, $result, $sx, $sy, $subregion);

            case 'feOffset':
                return new FeOffset($in, $result, (float) ($attrs['dx'] ?? 0), (float) ($attrs['dy'] ?? 0), $subregion);

            case 'feColorMatrix':
                $type = ColorMatrixType::fromString($attrs['type'] ?? '');
                return new FeColorMatrix($in, $result, $type, $this->parseNumberList($attrs['values'] ?? ''), $subregion);

            case 'feComposite':
                $operator = CompositeOperator::fromString($attrs['operator'] ?? '');
                return new FeComposite(
                    $in,
                    $in2,
                    $result,
                    $operator,
                    (float) ($attrs['k1'] ?? 0),
                    (float) ($attrs['k2'] ?? 0),
                    (float) ($attrs['k3'] ?? 0),
                    (float) ($attrs['k4'] ?? 0),
                    $subregion,
                );

            case 'feBlend':
                return new FeBlend($in, $in2, $result, BlendMode::fromString($attrs['mode'] ?? ''), $subregion);

            case 'feFlood':
                return new FeFlood(
                    $result,
                    $this->parseFloodColor($attrs['flood-color'] ?? null),
                    (float) ($attrs['flood-opacity'] ?? 1),
                    $subregion,
                );

            case 'feMerge':
                return new FeMerge($result, $this->parseMergeInputs($el), $subregion);

            case 'feDropShadow':
                [$sx, $sy] = $this->parseStdDeviation($attrs['stdDeviation'] ?? '', 2.0);
                return new FeDropShadow(
                    $in,
                    $result,
                    (float) ($attrs['dx'] ?? 2),
                    (float) ($attrs['dy'] ?? 2),
                    $sx,
                    $sy,
                    $this->parseFloodColor($attrs['flood-color'] ?? null),
                    (float) ($attrs['flood-opacity'] ?? 1),
                    $subregion,
                );

            default:
                return null;
        }
    }

    /**
     * Per-primitive subregion. Returns null when ALL four of x/y/width/height
     * are absent; otherwise individual missing components stay null.
     *
     * @param array<string, string> $attrs
     */
    private function parseSubregion(array $attrs): ?Subregion
    {
        $hasAny = isset($attrs['x']) || isset($attrs['y']) || isset($attrs['width']) || isset($attrs['height']);
        if (!$hasAny) {
            return null;
        }
        return new Subregion(
            isset($attrs['x']) ? $this->regionLength($attrs['x']) : null,
            isset($attrs['y']) ? $this->regionLength($attrs['y']) : null,
            isset($attrs['width']) ? $this->regionLength($attrs['width']) : null,
            isset($attrs['height']) ? $this->regionLength($attrs['height']) : null,
        );
    }

    /**
     * Parses a stdDeviation attribute (one or two numbers split on whitespace
     * or comma). One value sets x=y; an absent/empty value uses $default for
     * both.
     *
     * @return array{0: float, 1: float}
     */
    private function parseStdDeviation(string $raw, float $default = 0.0): array
    {
        $parts = $this->parseNumberList($raw);
        if ($parts === []) {
            return [$default, $default];
        }
        if (count($parts) === 1) {
            return [$parts[0], $parts[0]];
        }
        return [$parts[0], $parts[1]];
    }

    /**
     * Splits a whitespace/comma-separated numeric list into floats.
     *
     * @return list<float>
     */
    private function parseNumberList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(static fn (string $p): float => (float) $p, $parts);
    }

    /**
     * Parses a flood-color value via the shared color routine. Defaults to
     * black when absent or unparseable.
     */
    private function parseFloodColor(?string $raw): SvgColor
    {
        if ($raw === null || trim($raw) === '') {
            return SvgColor::black();
        }
        return ColorParser::parse(trim($raw), SvgColor::black()) ?? SvgColor::black();
    }

    /**
     * Collects the `in` attribute of each child <feMergeNode> into a list,
     * preserving order. A feMergeNode without `in` contributes a null entry.
     *
     * @return list<?string>
     */
    private function parseMergeInputs(DOMElement $el): array
    {
        $inputs = [];
        foreach ($this->childElements($el) as $child) {
            if ($child->namespaceURI === self::SVG_NS && $child->localName === 'feMergeNode') {
                $inputs[] = $child->hasAttribute('in') ? $child->getAttribute('in') : null;
            }
        }
        return $inputs;
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
            $this->inPattern || $this->inMarker ? null : $this->gradients,
            $this->inPattern || $this->inMarker ? null : $this->patterns,
            $this->inPattern || $this->inMarker ? null : $this->markers,
            $this->inPattern || $this->inMarker || $this->inMask ? null : $this->masks,
        );
        $transform = isset($attrs['transform']) ? TransformParser::parse($attrs['transform']) : null;
        $textStyle = TextStyleResolver::resolve($inheritedText, $attrs, $css, $attrs['style'] ?? '');

        // <defs>, <symbol>, <marker>, <mask>, and <filter> contents are only reachable via
        // reference; <title>/<desc> carry no geometry. All resolve to a null node here.
        $node = match ($local) {
            'g', 'svg' => $this->parseGroup($el, $paint, $newCurrentColor, $textStyle, $useStack, $depth, $allowClip, $transform),
            'rect' => $this->buildRect($attrs, $transform, $paint),
            'circle' => $this->buildCircle($attrs, $transform, $paint),
            'ellipse' => $this->buildEllipse($attrs, $transform, $paint),
            'line' => $this->buildLine($attrs, $transform, $paint),
            'polygon', 'polyline' => $this->buildPoly($local, $attrs, $transform, $paint),
            'path' => $this->buildPath($attrs, $transform, $paint),
            'text' => $this->buildText($el, $attrs, $transform, $paint, $textStyle),
            'use' => $this->resolveUse($el, $attrs, $paint, $newCurrentColor, $textStyle, $transform, $useStack, $depth, $allowClip),
            'image' => $this->buildImage($attrs, $transform, $paint),
            default => null,
        };

        return $this->decorate($node, $allowClip, $attrs, $css, $paint);
    }

    /**
     * Applies the clip / mask / filter wrappers shared by every renderable node, in the
     * canonical filter-outside-mask-outside-clip order. A null node passes straight through.
     *
     * @param array<string, string> $attrs
     * @param array<string, string> $css
     */
    private function decorate(?SvgNode $node, bool $allowClip, array $attrs, array $css, SvgPaint $paint): ?SvgNode
    {
        return $this->wrapFilter($this->wrapMask($this->wrapClip($node, $allowClip, $attrs, $css), $paint), $attrs, $css);
    }

    /**
     * @param list<string> $useStack
     */
    private function parseGroup(DOMElement $el, SvgPaint $paint, SvgColor $currentColor, SvgTextStyle $textStyle, array $useStack, int $depth, bool $allowClip, ?SvgMatrix $transform): SvgGroup
    {
        $children = [];
        foreach ($this->childElements($el) as $child) {
            $node = $this->parseNode($child, $paint, $currentColor, $textStyle, $useStack, $depth + 1, $allowClip);
            if ($node !== null) {
                $children[] = $node;
            }
        }
        return new SvgGroup($transform, $children);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildRect(array $attrs, ?SvgMatrix $transform, SvgPaint $paint): ?SvgRect
    {
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
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildCircle(array $attrs, ?SvgMatrix $transform, SvgPaint $paint): ?SvgCircle
    {
        $cx = (float) ($attrs['cx'] ?? 0);
        $cy = (float) ($attrs['cy'] ?? 0);
        $r = (float) ($attrs['r'] ?? 0);
        if ($r <= 0.0) {
            return null;
        }
        return new SvgCircle($transform, $paint, $cx, $cy, $r);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildEllipse(array $attrs, ?SvgMatrix $transform, SvgPaint $paint): ?SvgEllipse
    {
        $cx = (float) ($attrs['cx'] ?? 0);
        $cy = (float) ($attrs['cy'] ?? 0);
        $rx = (float) ($attrs['rx'] ?? 0);
        $ry = (float) ($attrs['ry'] ?? 0);
        if ($rx <= 0.0 || $ry <= 0.0) {
            return null;
        }
        return new SvgEllipse($transform, $paint, $cx, $cy, $rx, $ry);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildLine(array $attrs, ?SvgMatrix $transform, SvgPaint $paint): SvgLine
    {
        $x1 = (float) ($attrs['x1'] ?? 0);
        $y1 = (float) ($attrs['y1'] ?? 0);
        $x2 = (float) ($attrs['x2'] ?? 0);
        $y2 = (float) ($attrs['y2'] ?? 0);
        return new SvgLine($transform, $paint, $x1, $y1, $x2, $y2);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildPoly(string $local, array $attrs, ?SvgMatrix $transform, SvgPaint $paint): SvgPolygon|SvgPolyline|null
    {
        $points = $this->parsePoints($attrs['points'] ?? '');
        if ($points === []) {
            return null;
        }
        return $local === 'polygon'
            ? new SvgPolygon($transform, $paint, $points)
            : new SvgPolyline($transform, $paint, $points);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildPath(array $attrs, ?SvgMatrix $transform, SvgPaint $paint): ?SvgPath
    {
        $d = $attrs['d'] ?? '';
        if ($d === '') {
            return null;
        }
        $commands = PathDataParser::parse($d);
        if ($commands === []) {
            return null;
        }
        return new SvgPath($transform, $paint, $commands);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildText(DOMElement $el, array $attrs, ?SvgMatrix $transform, SvgPaint $paint, SvgTextStyle $textStyle): ?SvgNode
    {
        if ($this->inPattern || $this->inMarker) {
            return null;
        }
        $textPathChild = $this->firstChildElement($el, 'textPath');
        if ($textPathChild !== null) {
            return $this->parseTextPath($textPathChild, $paint, $textStyle, $transform);
        }
        return $this->parseText($el, $paint, $textStyle, $transform);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildImage(array $attrs, ?SvgMatrix $transform, SvgPaint $paint): ?SvgImage
    {
        if ($this->inPattern || $this->inMarker) {
            return null;
        }
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
        return new SvgImage($transform, $x, $y, $w, $h, $ar, $paint->opacity, $index, $raster->width, $raster->height);
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

        if ($target->localName === 'symbol') {
            return $this->resolveSymbolUse($target, $attrs, $paint, $currentColor, $inheritedText, $transform, $useStack, $depth, $allowClip);
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
     * Resolves a <use> whose target is a <symbol> element.
     * The resulting SvgGroup transform is (use translate) . (viewBox->useBox mapping).
     * Symbol children are parsed directly; the symbol itself never renders.
     *
     * @param array<string, string> $useAttrs
     * @param list<string> $useStack
     */
    private function resolveSymbolUse(
        DOMElement $symbolEl,
        array $useAttrs,
        SvgPaint $paint,
        SvgColor $currentColor,
        SvgTextStyle $inheritedText,
        ?SvgMatrix $useElementTransform,
        array $useStack,
        int $depth,
        bool $allowClip,
    ): SvgNode {
        $useX = (float) ($useAttrs['x'] ?? 0);
        $useY = (float) ($useAttrs['y'] ?? 0);

        $symbolViewBoxAttr = $symbolEl->getAttribute('viewBox');
        $viewBox = $symbolViewBoxAttr !== '' ? $this->parseViewBoxString($symbolViewBoxAttr) : null;

        $useWidth = isset($useAttrs['width']) ? (float) $useAttrs['width'] : ($viewBox !== null ? $viewBox->width : 0.0);
        $useHeight = isset($useAttrs['height']) ? (float) $useAttrs['height'] : ($viewBox !== null ? $viewBox->height : 0.0);

        $par = $symbolEl->hasAttribute('preserveAspectRatio')
            ? PreserveAspectRatio::parse($symbolEl->getAttribute('preserveAspectRatio'))
            : PreserveAspectRatio::default();

        $children = [];
        $symbolId = $symbolEl->getAttribute('id');
        foreach ($this->childElements($symbolEl) as $child) {
            $node = $this->parseNode(
                $child,
                $paint,
                $currentColor,
                $inheritedText,
                $symbolId !== '' ? [...$useStack, $symbolId] : $useStack,
                $depth + 1,
                $allowClip,
            );
            if ($node !== null) {
                $children[] = $node;
            }
        }

        $useTransform = SvgMatrix::translate($useX, $useY);
        if ($viewBox !== null && $useWidth > 0.0 && $useHeight > 0.0) {
            $vbMatrix = PreserveAspectRatio::matrixFor($viewBox, $useWidth, $useHeight, $par);
            $useTransform = $useTransform->compose($vbMatrix);
        }
        if ($useElementTransform !== null) {
            $useTransform = $useElementTransform->compose($useTransform);
        }

        return new SvgGroup($useTransform, $children);
    }

    /**
     * Parses a viewBox attribute string into a ViewBox value object.
     * Returns null for malformed or zero-dimension values.
     */
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
        $paint = StyleResolver::resolve($inheritedPaint, $attrs, $css, $attrs['style'] ?? '', $currentColor, $this->inPattern || $this->inMarker ? null : $this->gradients, $this->inPattern || $this->inMarker ? null : $this->patterns, $this->inPattern || $this->inMarker ? null : $this->markers, $this->inPattern || $this->inMarker || $this->inMask ? null : $this->masks);
        $style = TextStyleResolver::resolve($inheritedStyle, $attrs, $css, $attrs['style'] ?? '');

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
                    fontFamily: $style->fontFamily,
                    bold: $style->bold,
                    italic: $style->italic,
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

    private function parseTextPath(
        DOMElement $textPathEl,
        SvgPaint $paint,
        SvgTextStyle $style,
        ?SvgMatrix $transform,
    ): ?SvgTextPath {
        $attrs = $this->collectAttrs($textPathEl);
        $href = $attrs['href'] ?? $attrs['xlink:href'] ?? '';
        if ($href === '' || $href[0] !== '#') {
            return null;
        }
        $target = $this->defs[substr($href, 1)] ?? null;
        if (!$target instanceof DOMElement || $target->localName !== 'path') {
            return null;
        }
        $d = $target->getAttribute('d');
        if ($d === '') {
            return null;
        }
        $commands = PathDataParser::parse($d);
        if ($commands === []) {
            return null;
        }

        /** @var list<SvgTextSpan> $spans */
        $spans = [];
        $this->collectTextSpans($textPathEl, $paint, $style, $spans);
        $spans = array_values(array_filter($spans, static fn (SvgTextSpan $s): bool => trim($s->text) !== ''));
        if ($spans === []) {
            return null;
        }

        $rawOffset = $attrs['startOffset'] ?? '0';
        $isPercent = str_ends_with($rawOffset, '%');
        $startOffset = (float) $rawOffset;

        return new SvgTextPath($transform, $commands, $spans, $startOffset, $isPercent);
    }

    private function withText(SvgTextSpan $span, string $text): SvgTextSpan
    {
        return new SvgTextSpan(
            text: $text,
            fontFamily: $span->fontFamily,
            bold: $span->bold,
            italic: $span->italic,
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
     * Wraps a node in SvgMasked if its paint carries a mask. Called next to
     * wrapClip at every renderable shape/group/text/image return point.
     * The wrap order is mask-outside-clip: SvgMasked(SvgClipped(child)) so
     * clipping happens inside the mask region in the rendered q/Q nesting.
     */
    private function wrapMask(?SvgNode $node, SvgPaint $paint): ?SvgNode
    {
        if ($node === null) {
            return null;
        }
        if ($paint->mask === null || $this->inMask) {
            return $node;
        }
        return new SvgMasked($paint->mask, $node);
    }

    /**
     * Wraps a node in SvgFiltered if its filter presentation attribute or
     * `filter:` style declaration resolves to a registered <filter> def.
     * This is the outermost wrapper (filter outside mask outside clip), so the
     * renderer rasterizes the already-masked/clipped subtree before filtering.
     * `none`, empty, non-url() values, and unknown ids leave the node untouched.
     *
     * @param array<string, string> $attrs
     * @param array<string, string> $css
     */
    private function wrapFilter(?SvgNode $node, array $attrs, array $css): ?SvgNode
    {
        if ($node === null) {
            return null;
        }
        $value = $this->styleProp($attrs['style'] ?? '', 'filter')
            ?? ($css['filter'] ?? null)
            ?? ($attrs['filter'] ?? null);
        if ($value === null) {
            return $node;
        }
        $id = $this->clipUrlId($value);
        if ($id === null) {
            return $node;
        }
        $filter = $this->filters[$id] ?? null;
        return $filter !== null ? new SvgFiltered($filter, $node) : $node;
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
        // Simplified: one clip-rule per clipPath, taken from the first child that
        // declares one. Per-child mixed clip-rules are uncommon and out of scope.
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
     * Parses the children of a <pattern> element into SvgNode instances.
     * Toggles the inPattern flag so <text> and <image> elements are stripped.
     *
     * @return list<SvgNode>
     */
    public function parseChildrenAsPattern(DOMElement $patternEl, SvgColor $currentColor): array
    {
        $previous = $this->inPattern;
        $this->inPattern = true;
        try {
            $nodes = [];
            foreach ($this->childElements($patternEl) as $child) {
                $node = $this->parseNode(
                    $child,
                    SvgPaint::default(),
                    $currentColor,
                    SvgTextStyle::initial(),
                    [],
                    0,
                    allowClip: false,
                );
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
            return $nodes;
        } finally {
            $this->inPattern = $previous;
        }
    }

    /**
     * Parses the children of a <marker> element into SvgNode instances.
     * Toggles the inMarker flag so <text> and <image> are stripped and
     * nested paint-server url() refs degrade to inherited color.
     *
     * @return list<SvgNode>
     */
    public function parseChildrenAsMarker(DOMElement $markerEl, SvgColor $currentColor): array
    {
        $previous = $this->inMarker;
        $this->inMarker = true;
        try {
            $nodes = [];
            foreach ($this->childElements($markerEl) as $child) {
                $node = $this->parseNode(
                    $child,
                    SvgPaint::default(),
                    $currentColor,
                    SvgTextStyle::initial(),
                    [],
                    0,
                    allowClip: false,
                );
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
            return $nodes;
        } finally {
            $this->inMarker = $previous;
        }
    }

    /**
     * Parses the children of a <mask> element into SvgNode instances.
     * Toggles the inMask flag so nested mask=url() refs inside the mask are
     * silently ignored (handled by StyleResolver receiving masks=null), while
     * gradient/pattern paint servers remain available for the mask's children.
     *
     * @return list<SvgNode>
     */
    public function parseChildrenAsMask(DOMElement $maskEl, SvgColor $currentColor): array
    {
        $previous = $this->inMask;
        $this->inMask = true;
        try {
            $nodes = [];
            foreach ($this->childElements($maskEl) as $child) {
                $node = $this->parseNode(
                    $child,
                    SvgPaint::default(),
                    $currentColor,
                    SvgTextStyle::initial(),
                    [],
                    0,
                    allowClip: true,
                );
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
            return $nodes;
        } finally {
            $this->inMask = $previous;
        }
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

    private function firstChildElement(DOMElement $el, string $localName): ?DOMElement
    {
        foreach ($this->childElements($el) as $child) {
            if ($child->namespaceURI === self::SVG_NS && $child->localName === $localName) {
                return $child;
            }
        }
        return null;
    }
}
