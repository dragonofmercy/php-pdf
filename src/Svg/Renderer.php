<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\BoundingBox;
use DragonOfMercy\PhpPdf\Svg\ClipPathUnits;
use DragonOfMercy\PhpPdf\Svg\EmbeddedMask;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerKind;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerOrientMode;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerPosition;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerPositioner;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerUnits;
use DragonOfMercy\PhpPdf\Svg\Marker\SvgMarker;
use DragonOfMercy\PhpPdf\Svg\SvgClip;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgShape;
use DragonOfMercy\PhpPdf\Svg\TextPath\PathPolyline;

/**
 * Translates an SvgMetadata tree into a PDF content-stream byte string.
 *
 * @internal
 */
final class Renderer
{
    private FontRegistry $fontRegistry;
    private MetricsRegistry $metricsRegistry;

    /** @var array<string, true> short names of fonts used by text in this render */
    private array $usedFonts = [];

    /** @var list<EmbeddedPattern> tiling pattern records accumulated during render */
    private array $embeddedPatterns = [];

    /** @var list<EmbeddedMask> soft-mask records accumulated during render */
    private array $embeddedMasks = [];

    /** @var array<string, FontEngine> engine cache keyed by pdfName (standard) or custom alias+variant */
    private array $engines = [];

    /** @var array<string, string> lowercased registered alias => actual alias */
    private array $fontAliases = [];

    private ?FontResolver $fontResolver = null;

    public function __construct()
    {
        $this->metricsRegistry = new MetricsRegistry();
    }

    /**
     * @return array{
     *     bytes: string,
     *     extGStates: array<string, array{ca: float, CA: float, smaskEmbeddedIndex: ?int}>,
     *     patterns: array<string, string>,
     *     patternRefs: list<array{name: string, embeddedIndex: int}>,
     *     embeddedPatterns: list<EmbeddedPattern>,
     *     embeddedMasks: list<EmbeddedMask>,
     *     fonts: list<string>,
     * }
     */
    public function render(SvgMetadata $svg, ?FontRegistry $fontRegistry = null, ?FontResolver $fontResolver = null): array
    {
        $this->fontRegistry = $fontRegistry ?? new FontRegistry();
        $this->fontResolver = $fontResolver;
        // The alias map travels with the resolver: without one there is no custom
        // context, so resolveSpanFont can never yield a custom Font that engineFor
        // cannot build.
        $this->fontAliases = $fontResolver?->registeredAliases() ?? [];
        $this->engines = [];
        $this->usedFonts = [];
        $this->embeddedPatterns = [];
        $this->embeddedMasks = [];
        $out = '';
        $registry = new ExtGStateRegistry();
        $patterns = new PatternRegistry();
        $prologue = self::viewBoxToUnitMatrix($svg->viewBox);
        if (!$prologue->isIdentity()) {
            $out .= "q\n" . self::cmFromMatrix($prologue) . "\n";
        }

        foreach ($svg->root->children as $child) {
            $out .= $this->renderNode($child, $registry, $patterns, $prologue);
        }

        if (!$prologue->isIdentity()) {
            $out .= "Q\n";
        }

        return [
            'bytes' => $out,
            'extGStates' => $registry->entries(),
            'patterns' => $patterns->entries(),
            'patternRefs' => $patterns->refEntries(),
            'embeddedPatterns' => $this->embeddedPatterns,
            'embeddedMasks' => $this->embeddedMasks,
            'fonts' => array_keys($this->usedFonts),
        ];
    }

    /**
     * Maps the viewBox onto the Form's unit square, filling both axes and
     * flipping the Y axis.
     *
     * The page places this Form's unit square at the image's effective width
     * and height, which already carry the viewBox aspect ratio, so filling
     * both axes here keeps the net (prologue then placement) transform uniform
     * and undistorted. The Y flip is required because the page places every
     * image XObject with a vertical-flip matrix (designed for top-row-first
     * rasters); without it the SVG's top-down content would render upside down.
     *
     * The root preserveAspectRatio (meet / slice / align) is intentionally not
     * applied here: it can only be resolved against the placement viewport,
     * which lives at the Page::image layer, not in this Form-local prologue.
     */
    public static function viewBoxToUnitMatrix(ViewBox $vb): SvgMatrix
    {
        $sx = 1.0 / $vb->width;
        $sy = 1.0 / $vb->height;
        return SvgMatrix::translate(-$vb->x * $sx, 1.0 + $vb->y * $sy)
            ->compose(SvgMatrix::scale($sx, -$sy));
    }

    private function renderNode(SvgNode $node, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        if ($node instanceof SvgGroup) {
            return $this->renderGroup($node, $registry, $patterns, $ctm);
        }
        if ($node instanceof SvgShape) {
            return $this->renderShape($node, $registry, $patterns, $ctm);
        }
        if ($node instanceof SvgImage) {
            return $this->renderImage($node, $registry);
        }
        if ($node instanceof SvgText) {
            return $this->renderText($node, $registry);
        }
        if ($node instanceof SvgTextPath) {
            return $this->renderTextPath($node, $registry);
        }
        if ($node instanceof SvgClipped) {
            return $this->renderClipped($node, $registry, $patterns, $ctm);
        }
        if ($node instanceof SvgMasked) {
            return $this->renderMasked($node, $registry, $patterns, $ctm);
        }
        return '';
    }

    private function renderGroup(SvgGroup $group, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        if ($group->children === []) {
            return '';
        }
        $childCtm = $group->transform !== null ? $ctm->compose($group->transform) : $ctm;
        $body = '';
        foreach ($group->children as $child) {
            $body .= $this->renderNode($child, $registry, $patterns, $childCtm);
        }
        if ($body === '') {
            return '';
        }
        $cm = ($group->transform !== null && !$group->transform->isIdentity())
            ? self::cmFromMatrix($group->transform) . "\n"
            : '';
        return "q\n" . $cm . $body . "Q\n";
    }

    private function renderShape(SvgShape $shape, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        $geom = $this->emitGeometry($shape);
        if ($geom === '') {
            return '';
        }
        $tf = $shape->transform();
        $shapeCtm = $tf !== null ? $ctm->compose($tf) : $ctm;
        $paint = $shape->paint();
        $stateOps = $this->emitPaintState($paint, $registry, $patterns, $shape, $shapeCtm);
        $terminator = $this->paintTerminator($paint);
        $cmLine = ($tf !== null && !$tf->isIdentity()) ? self::cmFromMatrix($tf) . "\n" : '';
        $markerOps = $this->emitMarkersFor($shape, $paint, $registry, $patterns, $shapeCtm);
        return "q\n" . $cmLine . $stateOps . $geom . $terminator . "\n" . $markerOps . "Q\n";
    }

    private function emitPaintState(SvgPaint $paint, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgShape $shape, SvgMatrix $shapeCtm): string
    {
        $out = '';
        $fillOpacity = $paint->effectiveFillOpacity();
        $strokeOpacity = $paint->effectiveStrokeOpacity();

        if ($paint->fill instanceof SvgColor) {
            $out .= sprintf("%s %s %s rg\n", self::fmt($paint->fill->r), self::fmt($paint->fill->g), self::fmt($paint->fill->b));
        } elseif ($paint->fill instanceof SvgGradient) {
            $resolved = $this->paintGradient($paint->fill, $shape, $shapeCtm, $patterns, $registry, $fillOpacity, false);
            $out .= $resolved['ops'];
            $fillOpacity = $resolved['opacity'];
        } elseif ($paint->fill instanceof SvgPattern) {
            $resolved = $this->paintTilingPattern($paint->fill, $shape, $shapeCtm, $patterns, $fillOpacity, false);
            $out .= $resolved['ops'];
            $fillOpacity = $resolved['opacity'];
        }

        if ($paint->stroke instanceof SvgColor) {
            $out .= sprintf("%s %s %s RG\n", self::fmt($paint->stroke->r), self::fmt($paint->stroke->g), self::fmt($paint->stroke->b));
        } elseif ($paint->stroke instanceof SvgGradient) {
            $resolved = $this->paintGradient($paint->stroke, $shape, $shapeCtm, $patterns, $registry, $strokeOpacity, true);
            $out .= $resolved['ops'];
            $strokeOpacity = $resolved['opacity'];
        } elseif ($paint->stroke instanceof SvgPattern) {
            $resolved = $this->paintTilingPattern($paint->stroke, $shape, $shapeCtm, $patterns, $strokeOpacity, true);
            $out .= $resolved['ops'];
            $strokeOpacity = $resolved['opacity'];
        }

        if ($paint->stroke !== null) {
            $out .= sprintf("%s w\n", self::fmt($paint->strokeWidth));
            $out .= sprintf("%d J\n", $paint->strokeLineCap->toPdfCode());
            $out .= sprintf("%d j\n", $paint->strokeLineJoin->toPdfCode());
            if ($paint->strokeMiterLimit !== 4.0) {
                $out .= sprintf("%s M\n", self::fmt($paint->strokeMiterLimit));
            }
            if ($paint->strokeDashArray !== []) {
                $parts = array_map(static fn (float $v): string => self::fmt($v), $paint->strokeDashArray);
                $out .= sprintf("[%s] %s d\n", implode(' ', $parts), self::fmt($paint->strokeDashOffset));
            }
        }

        $name = $registry->nameFor($fillOpacity, $strokeOpacity);
        if ($name !== '') {
            $out .= '/' . $name . " gs\n";
        }
        return $out;
    }

    /**
     * Registers the gradient pattern and returns the color-space ops plus the
     * effective opacity. When the gradient's stops vary in opacity, additionally
     * emits a grayscale alpha shading wrapped in a soft-mask Form (registered as
     * an EmbeddedMask), and prepends /gs to the returned ops so the SMask wraps
     * the color paint.
     *
     * @return array{ops: string, opacity: float}
     */
    private function paintGradient(SvgGradient $gradient, SvgShape $shape, SvgMatrix $shapeCtm, PatternRegistry $patterns, ExtGStateRegistry $registry, float $baseOpacity, bool $isStroke): array
    {
        $matrix = $shapeCtm;
        if ($gradient->units() === GradientUnits::OBJECT_BOUNDING_BOX) {
            $bbox = BoundingBox::of($shape);
            if ($bbox->isDegenerate()) {
                $c = $gradient->stops()[0]->color;
                $op = $isStroke ? "%s %s %s RG\n" : "%s %s %s rg\n";
                return ['ops' => sprintf($op, self::fmt($c->r), self::fmt($c->g), self::fmt($c->b)), 'opacity' => $baseOpacity];
            }
            $matrix = $matrix
                ->compose(SvgMatrix::translate($bbox->x, $bbox->y))
                ->compose(SvgMatrix::scale($bbox->width, $bbox->height));
        }
        $gt = $gradient->transform();
        if ($gt !== null) {
            $matrix = $matrix->compose($gt);
        }
        if ($gradient->spreadMethod() !== SpreadMethod::PAD) {
            $localBbox = $gradient->units() === GradientUnits::OBJECT_BOUNDING_BOX
                ? new BoundingBox(0.0, 0.0, 1.0, 1.0)
                : BoundingBox::of($shape);
            $gradient = GradientSpread::expand($gradient, $localBbox);
        }

        $varying = GradientResolver::hasVaryingAlpha($gradient->stops());
        if (!$varying) {
            $dict = ShadingBuilder::patternDict($gradient, $matrix);
            $name = $patterns->nameFor($dict);
            $ops = $isStroke ? "/Pattern CS\n/$name SCN\n" : "/Pattern cs\n/$name scn\n";
            return ['ops' => $ops, 'opacity' => $baseOpacity * $gradient->uniformOpacity()];
        }

        // Varying alpha path: emit a soft-mask Form whose content paints an alpha
        // shading sized to the shape's bbox. The color shading uses opacity=1.0
        // stops (the alpha is already provided by the SMask).
        $opaqueStops = [];
        foreach ($gradient->stops() as $s) {
            $opaqueStops[] = new GradientStop($s->offset, $s->color, 1.0);
        }
        $colorGradient = $gradient instanceof RadialGradient
            ? new RadialGradient($gradient->cx, $gradient->cy, $gradient->r, $gradient->fx, $gradient->fy, $gradient->units(), $gradient->transform(), $opaqueStops, 1.0, $gradient->spreadMethod())
            : new LinearGradient(
                $gradient instanceof LinearGradient ? $gradient->x1 : 0.0,
                $gradient instanceof LinearGradient ? $gradient->y1 : 0.0,
                $gradient instanceof LinearGradient ? $gradient->x2 : 1.0,
                $gradient instanceof LinearGradient ? $gradient->y2 : 0.0,
                $gradient->units(), $gradient->transform(), $opaqueStops, 1.0, $gradient->spreadMethod());

        $colorDict = ShadingBuilder::patternDict($colorGradient, $matrix);
        $colorName = $patterns->nameFor($colorDict);

        // Alpha pattern matrix omits $shapeCtm. The SMask Form has its own
        // local coord system (per /BBox in user-space) and pattern matrices
        // inside are interpreted relative to that. Baking $shapeCtm in would
        // double-apply the viewBox-to-unit projection and collapse the
        // gradient to a tiny strip in the top-left.
        $alphaMatrix = SvgMatrix::identity();
        if ($gradient->units() === GradientUnits::OBJECT_BOUNDING_BOX) {
            $alphaBbox = BoundingBox::of($shape);
            $alphaMatrix = SvgMatrix::translate($alphaBbox->x, $alphaBbox->y)
                ->compose(SvgMatrix::scale($alphaBbox->width, $alphaBbox->height));
        }
        if ($gt !== null) {
            $alphaMatrix = $alphaMatrix->compose($gt);
        }
        $alphaDict = ShadingBuilder::alphaPatternDict($gradient, $alphaMatrix);
        $innerPatterns = new PatternRegistry();
        $alphaName = $innerPatterns->nameFor($alphaDict);

        $shapeBbox = BoundingBox::of($shape);
        $contentBytes = "/Pattern cs\n/$alphaName scn\n"
            . sprintf("%s %s %s %s re f\n", self::fmt($shapeBbox->x), self::fmt($shapeBbox->y), self::fmt($shapeBbox->width), self::fmt($shapeBbox->height));

        $maskBbox = [$shapeBbox->x, $shapeBbox->y, $shapeBbox->x + $shapeBbox->width, $shapeBbox->y + $shapeBbox->height];

        $embeddedIndex = count($this->embeddedMasks);
        $this->embeddedMasks[] = new EmbeddedMask(
            bbox: $maskBbox,
            matrix: SvgMatrix::identity()->toArray(),
            extGStates: [],
            patterns: $innerPatterns->entries(),
            contentBytes: $contentBytes,
        );

        $smaskName = $registry->nameForWithMask(1.0, 1.0, $embeddedIndex);
        $colorOps = $isStroke ? "/Pattern CS\n/$colorName SCN\n" : "/Pattern cs\n/$colorName scn\n";
        $ops = "/$smaskName gs\n" . $colorOps;

        return ['ops' => $ops, 'opacity' => $baseOpacity * $gradient->uniformOpacity()];
    }

    /**
     * Renders the tile children into a sub-stream with its own ExtGStateRegistry,
     * records an EmbeddedPattern, and returns the painting ops that select the
     * tiling pattern as the current color. ImageEmbedder will allocate the
     * child indirect object using the recorded EmbeddedPattern.
     *
     * @return array{ops: string, opacity: float}
     */
    private function paintTilingPattern(SvgPattern $pattern, SvgShape $shape, SvgMatrix $shapeCtm, PatternRegistry $patterns, float $baseOpacity, bool $isStroke): array
    {
        // PDF /XStep and /YStep must be non-zero. A malformed <pattern> with a
        // zero dimension passes the resolver (which only checks for zero children)
        // but would produce an invalid pattern dict. Fall back to solid black.
        if ($pattern->width <= 0.0 || $pattern->height <= 0.0) {
            $op = $isStroke ? "0 0 0 RG\n" : "0 0 0 rg\n";
            return ['ops' => $op, 'opacity' => $baseOpacity];
        }

        // Compute the pattern dict /Matrix mapping pattern space -> page space.
        $matrix = $shapeCtm;
        if ($pattern->units === PatternUnits::OBJECT_BOUNDING_BOX) {
            $bbox = BoundingBox::of($shape);
            if ($bbox->isDegenerate()) {
                $op = $isStroke ? "0 0 0 RG\n" : "0 0 0 rg\n";
                return ['ops' => $op, 'opacity' => $baseOpacity];
            }
            $matrix = $matrix
                ->compose(SvgMatrix::translate($bbox->x, $bbox->y))
                ->compose(SvgMatrix::scale($bbox->width, $bbox->height));
        }
        if ($pattern->transform !== null) {
            $matrix = $matrix->compose($pattern->transform);
        }

        // Sub-render the tile content with its own ExtGStateRegistry and pattern registry.
        // The nested PatternRegistry is unused (pattern children scrubbed at parse time)
        // but the renderNode API requires one.
        $innerRegistry = new ExtGStateRegistry();
        $innerPatterns = new PatternRegistry();
        $ctmForChildren = SvgMatrix::identity();
        if ($pattern->viewBox !== null) {
            $sx = $pattern->width / $pattern->viewBox->width;
            $sy = $pattern->height / $pattern->viewBox->height;
            $ctmForChildren = SvgMatrix::translate(-$pattern->viewBox->x * $sx, -$pattern->viewBox->y * $sy)
                ->compose(SvgMatrix::scale($sx, $sy));
        }
        $contentBytes = '';
        if (!$ctmForChildren->isIdentity()) {
            $contentBytes .= "q\n" . self::cmFromMatrix($ctmForChildren) . "\n";
        }
        foreach ($pattern->nodes as $childNode) {
            $contentBytes .= $this->renderNode($childNode, $innerRegistry, $innerPatterns, $ctmForChildren);
        }
        if (!$ctmForChildren->isIdentity()) {
            $contentBytes .= "Q\n";
        }

        // Record structured fields; ImageEmbedder builds the PDF dict.
        $embeddedIndex = count($this->embeddedPatterns);
        $this->embeddedPatterns[] = new EmbeddedPattern(
            bbox: [$pattern->x, $pattern->y, $pattern->x + $pattern->width, $pattern->y + $pattern->height],
            xStep: $pattern->width,
            yStep: $pattern->height,
            matrix: $matrix->toArray(),
            extGStates: $innerRegistry->entries(),
            contentBytes: $contentBytes,
        );
        $name = $patterns->nameForTiling($embeddedIndex);

        $ops = $isStroke ? "/Pattern CS\n/$name SCN\n" : "/Pattern cs\n/$name scn\n";
        return ['ops' => $ops, 'opacity' => $baseOpacity];
    }

    /**
     * Renders the markers attached to a shape's paint as inline q/cm/.../Q
     * blocks. Marker children paint in their own paint state; the shape's
     * stroke/fill state is implicitly overridden when the children set their
     * own. Marker position is in the shape's local space (post shape transform
     * via the surrounding q/Q wrapping).
     */
    private function emitMarkersFor(SvgShape $shape, SvgPaint $paint, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $shapeCtm): string
    {
        $set = $paint->markers;
        if ($set === null) {
            return '';
        }
        $positions = MarkerPositioner::positionsFor($shape);
        if ($positions === []) {
            return '';
        }

        $strokeWidth = $paint->strokeWidth;
        $out = '';
        foreach ($positions as $pos) {
            $marker = match ($pos->kind) {
                MarkerKind::START => $set->start,
                MarkerKind::MID   => $set->mid,
                MarkerKind::END   => $set->end,
            };
            if ($marker === null) {
                continue;
            }
            $out .= $this->emitOneMarker($marker, $pos, $strokeWidth, $registry, $patterns, $shapeCtm);
        }
        return $out;
    }

    private function emitOneMarker(SvgMarker $marker, MarkerPosition $pos, float $strokeWidth, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $shapeCtm): string
    {
        $angle = match ($marker->orient->mode) {
            MarkerOrientMode::NUMBER             => $marker->orient->angleDeg,
            MarkerOrientMode::AUTO               => $pos->angleDeg,
            MarkerOrientMode::AUTO_START_REVERSE => $pos->angleDeg + ($pos->kind === MarkerKind::START ? 180.0 : 0.0),
        };
        $scale = $marker->units === MarkerUnits::STROKE_WIDTH ? $strokeWidth : 1.0;

        $m = SvgMatrix::translate($pos->x, $pos->y)
            ->compose(SvgMatrix::rotate($angle))
            ->compose(SvgMatrix::scale($scale, $scale));
        if ($marker->viewBox !== null) {
            $vbMatrix = PreserveAspectRatio::matrixFor($marker->viewBox, $marker->markerWidth, $marker->markerHeight, $marker->aspectRatio);
            $m = $m->compose($vbMatrix);
        }
        $m = $m->compose(SvgMatrix::translate(-$marker->refX, -$marker->refY));

        $childCtm = $shapeCtm->compose($m);
        $out = "q\n" . self::cmFromMatrix($m) . "\n";
        foreach ($marker->nodes as $node) {
            $out .= $this->renderNode($node, $registry, $patterns, $childCtm);
        }
        $out .= "Q\n";
        return $out;
    }

    private function paintTerminator(SvgPaint $paint): string
    {
        $hasFill = $paint->fill !== null;
        $hasStroke = $paint->stroke !== null;
        $evenodd = $paint->fillRule === FillRule::EVENODD;
        if ($hasFill && $hasStroke) {
            return $evenodd ? 'B*' : 'B';
        }
        if ($hasFill) {
            return $evenodd ? 'f*' : 'f';
        }
        if ($hasStroke) {
            return 'S';
        }
        return 'n';
    }

    private function emitGeometry(SvgShape $shape, ?SvgMatrix $transform = null): string
    {
        return match (true) {
            $shape instanceof SvgRect     => $this->emitRect($shape, $transform),
            $shape instanceof SvgCircle   => $this->emitCircle($shape, $transform),
            $shape instanceof SvgEllipse  => $this->emitEllipse($shape, $transform),
            $shape instanceof SvgLine     => $this->emitLine($shape, $transform),
            $shape instanceof SvgPolygon  => $this->emitPolygon($shape, closed: true, transform: $transform),
            $shape instanceof SvgPolyline => $this->emitPolygon($shape, closed: false, transform: $transform),
            $shape instanceof SvgPath     => $this->emitPath($shape, $transform),
            default                       => '',
        };
    }

    /**
     * Maps an optional transform over a point. Returns the raw coordinates when
     * no transform is present so the null path is byte-identical to the original.
     *
     * @return array{0: float, 1: float}
     */
    private function pt(?SvgMatrix $t, float $x, float $y): array
    {
        return $t !== null ? $t->apply($x, $y) : [$x, $y];
    }

    private function emitRect(SvgRect $r, ?SvgMatrix $transform = null): string
    {
        if (!$r->hasRoundedCorners()) {
            if ($transform === null) {
                // Byte-identical output for non-clip path (null transform).
                return sprintf("%s %s %s %s re\n", self::fmt($r->x), self::fmt($r->y), self::fmt($r->width), self::fmt($r->height));
            }
            // `re` cannot encode rotation/skew; emit the four transformed corners as m/l/l/l/h.
            [$ax, $ay] = $transform->apply($r->x, $r->y);
            [$bx, $by] = $transform->apply($r->x + $r->width, $r->y);
            [$cx, $cy] = $transform->apply($r->x + $r->width, $r->y + $r->height);
            [$dx, $dy] = $transform->apply($r->x, $r->y + $r->height);
            return sprintf("%s %s m\n", self::fmt($ax), self::fmt($ay))
                . sprintf("%s %s l\n", self::fmt($bx), self::fmt($by))
                . sprintf("%s %s l\n", self::fmt($cx), self::fmt($cy))
                . sprintf("%s %s l\n", self::fmt($dx), self::fmt($dy))
                . "h\n";
        }
        // Clamp radii per SVG spec: rx <= width/2, ry <= height/2.
        $rx = min($r->rx > 0.0 ? $r->rx : $r->ry, $r->width / 2.0);
        $ry = min($r->ry > 0.0 ? $r->ry : $r->rx, $r->height / 2.0);
        $x = $r->x;
        $y = $r->y;
        $w = $r->width;
        $h = $r->height;
        $out = '';
        [$sx, $sy] = $this->pt($transform, $x + $w - $rx, $y);
        $out .= sprintf("%s %s m\n", self::fmt($sx), self::fmt($sy));
        // Top-right corner arc (quarter circle from (x+w-rx, y) to (x+w, y+ry))
        foreach (ArcToBezier::approximate($x + $w - $rx, $y, $rx, $ry, 0.0, false, true, $x + $w, $y + $ry) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            [$tc1x, $tc1y] = $this->pt($transform, $c1x, $c1y);
            [$tc2x, $tc2y] = $this->pt($transform, $c2x, $c2y);
            [$tex, $tey]   = $this->pt($transform, $ex, $ey);
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($tc1x), self::fmt($tc1y), self::fmt($tc2x), self::fmt($tc2y), self::fmt($tex), self::fmt($tey));
        }
        // Right edge
        [$rx2, $ry2] = $this->pt($transform, $x + $w, $y + $h - $ry);
        $out .= sprintf("%s %s l\n", self::fmt($rx2), self::fmt($ry2));
        // Bottom-right corner arc
        foreach (ArcToBezier::approximate($x + $w, $y + $h - $ry, $rx, $ry, 0.0, false, true, $x + $w - $rx, $y + $h) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            [$tc1x, $tc1y] = $this->pt($transform, $c1x, $c1y);
            [$tc2x, $tc2y] = $this->pt($transform, $c2x, $c2y);
            [$tex, $tey]   = $this->pt($transform, $ex, $ey);
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($tc1x), self::fmt($tc1y), self::fmt($tc2x), self::fmt($tc2y), self::fmt($tex), self::fmt($tey));
        }
        // Bottom edge
        [$bex, $bey] = $this->pt($transform, $x + $rx, $y + $h);
        $out .= sprintf("%s %s l\n", self::fmt($bex), self::fmt($bey));
        // Bottom-left corner arc
        foreach (ArcToBezier::approximate($x + $rx, $y + $h, $rx, $ry, 0.0, false, true, $x, $y + $h - $ry) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            [$tc1x, $tc1y] = $this->pt($transform, $c1x, $c1y);
            [$tc2x, $tc2y] = $this->pt($transform, $c2x, $c2y);
            [$tex, $tey]   = $this->pt($transform, $ex, $ey);
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($tc1x), self::fmt($tc1y), self::fmt($tc2x), self::fmt($tc2y), self::fmt($tex), self::fmt($tey));
        }
        // Left edge
        [$lex, $ley] = $this->pt($transform, $x, $y + $ry);
        $out .= sprintf("%s %s l\n", self::fmt($lex), self::fmt($ley));
        // Top-left corner arc
        foreach (ArcToBezier::approximate($x, $y + $ry, $rx, $ry, 0.0, false, true, $x + $rx, $y) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            [$tc1x, $tc1y] = $this->pt($transform, $c1x, $c1y);
            [$tc2x, $tc2y] = $this->pt($transform, $c2x, $c2y);
            [$tex, $tey]   = $this->pt($transform, $ex, $ey);
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($tc1x), self::fmt($tc1y), self::fmt($tc2x), self::fmt($tc2y), self::fmt($tex), self::fmt($tey));
        }
        $out .= "h\n";
        return $out;
    }

    private function emitCircle(SvgCircle $c, ?SvgMatrix $transform = null): string
    {
        return $this->emitEllipsoid($c->cx, $c->cy, $c->r, $c->r, $transform);
    }

    private function emitEllipse(SvgEllipse $e, ?SvgMatrix $transform = null): string
    {
        return $this->emitEllipsoid($e->cx, $e->cy, $e->rx, $e->ry, $transform);
    }

    /**
     * Four-cubic Bezier-kappa approximation of an ellipse, matching the
     * algorithm already used by Page::circle().
     */
    private function emitEllipsoid(float $cx, float $cy, float $rx, float $ry, ?SvgMatrix $transform = null): string
    {
        $k = 0.5522847498;
        $kx = $rx * $k;
        $ky = $ry * $k;
        [$p0x, $p0y]   = $this->pt($transform, $cx + $rx, $cy);
        [$p1ax, $p1ay] = $this->pt($transform, $cx + $rx, $cy + $ky);
        [$p1bx, $p1by] = $this->pt($transform, $cx + $kx, $cy + $ry);
        [$p1cx, $p1cy] = $this->pt($transform, $cx, $cy + $ry);
        [$p2ax, $p2ay] = $this->pt($transform, $cx - $kx, $cy + $ry);
        [$p2bx, $p2by] = $this->pt($transform, $cx - $rx, $cy + $ky);
        [$p2cx, $p2cy] = $this->pt($transform, $cx - $rx, $cy);
        [$p3ax, $p3ay] = $this->pt($transform, $cx - $rx, $cy - $ky);
        [$p3bx, $p3by] = $this->pt($transform, $cx - $kx, $cy - $ry);
        [$p3cx, $p3cy] = $this->pt($transform, $cx, $cy - $ry);
        [$p4ax, $p4ay] = $this->pt($transform, $cx + $kx, $cy - $ry);
        [$p4bx, $p4by] = $this->pt($transform, $cx + $rx, $cy - $ky);
        [$p4cx, $p4cy] = $this->pt($transform, $cx + $rx, $cy);
        return sprintf("%s %s m\n", self::fmt($p0x), self::fmt($p0y))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($p1ax), self::fmt($p1ay),
                self::fmt($p1bx), self::fmt($p1by),
                self::fmt($p1cx), self::fmt($p1cy))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($p2ax), self::fmt($p2ay),
                self::fmt($p2bx), self::fmt($p2by),
                self::fmt($p2cx), self::fmt($p2cy))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($p3ax), self::fmt($p3ay),
                self::fmt($p3bx), self::fmt($p3by),
                self::fmt($p3cx), self::fmt($p3cy))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($p4ax), self::fmt($p4ay),
                self::fmt($p4bx), self::fmt($p4by),
                self::fmt($p4cx), self::fmt($p4cy))
            . "h\n";
    }

    private function emitLine(SvgLine $l, ?SvgMatrix $transform = null): string
    {
        [$x1, $y1] = $this->pt($transform, $l->x1, $l->y1);
        [$x2, $y2] = $this->pt($transform, $l->x2, $l->y2);
        return sprintf("%s %s m\n", self::fmt($x1), self::fmt($y1))
            . sprintf("%s %s l\n", self::fmt($x2), self::fmt($y2));
    }

    private function emitPolygon(SvgPolygon|SvgPolyline $p, bool $closed, ?SvgMatrix $transform = null): string
    {
        if ($p->points === []) {
            return '';
        }
        [$fx, $fy] = $this->pt($transform, $p->points[0][0], $p->points[0][1]);
        $out = sprintf("%s %s m\n", self::fmt($fx), self::fmt($fy));
        for ($i = 1, $n = count($p->points); $i < $n; $i++) {
            [$lx, $ly] = $this->pt($transform, $p->points[$i][0], $p->points[$i][1]);
            $out .= sprintf("%s %s l\n", self::fmt($lx), self::fmt($ly));
        }
        if ($closed) {
            $out .= "h\n";
        }
        return $out;
    }

    private function emitPath(SvgPath $p, ?SvgMatrix $transform = null): string
    {
        $out = '';
        $cx = 0.0;
        $cy = 0.0;
        foreach ($p->commands as $cmd) {
            if ($cmd instanceof MoveTo) {
                [$tx, $ty] = $this->pt($transform, $cmd->x, $cmd->y);
                $out .= sprintf("%s %s m\n", self::fmt($tx), self::fmt($ty));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof LineTo) {
                [$tx, $ty] = $this->pt($transform, $cmd->x, $cmd->y);
                $out .= sprintf("%s %s l\n", self::fmt($tx), self::fmt($ty));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof CubicBezier) {
                [$tc1x, $tc1y] = $this->pt($transform, $cmd->c1x, $cmd->c1y);
                [$tc2x, $tc2y] = $this->pt($transform, $cmd->c2x, $cmd->c2y);
                [$tex, $tey]   = $this->pt($transform, $cmd->x, $cmd->y);
                $out .= sprintf("%s %s %s %s %s %s c\n",
                    self::fmt($tc1x), self::fmt($tc1y),
                    self::fmt($tc2x), self::fmt($tc2y),
                    self::fmt($tex), self::fmt($tey));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof QuadraticBezier) {
                // Elevate Q to cubic: C1 = current + 2/3*(Q - current), C2 = end + 2/3*(Q - end)
                $c1x = $cx + (2.0 / 3.0) * ($cmd->cx - $cx);
                $c1y = $cy + (2.0 / 3.0) * ($cmd->cy - $cy);
                $c2x = $cmd->x + (2.0 / 3.0) * ($cmd->cx - $cmd->x);
                $c2y = $cmd->y + (2.0 / 3.0) * ($cmd->cy - $cmd->y);
                [$tc1x, $tc1y] = $this->pt($transform, $c1x, $c1y);
                [$tc2x, $tc2y] = $this->pt($transform, $c2x, $c2y);
                [$tex, $tey]   = $this->pt($transform, $cmd->x, $cmd->y);
                $out .= sprintf("%s %s %s %s %s %s c\n",
                    self::fmt($tc1x), self::fmt($tc1y),
                    self::fmt($tc2x), self::fmt($tc2y),
                    self::fmt($tex), self::fmt($tey));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof Arc) {
                $beziers = ArcToBezier::approximate(
                    $cx, $cy, $cmd->rx, $cmd->ry, $cmd->xAxisRotationDeg,
                    $cmd->largeArc, $cmd->sweep, $cmd->x, $cmd->y,
                );
                foreach ($beziers as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
                    [$tc1x, $tc1y] = $this->pt($transform, $c1x, $c1y);
                    [$tc2x, $tc2y] = $this->pt($transform, $c2x, $c2y);
                    [$tex, $tey]   = $this->pt($transform, $ex, $ey);
                    $out .= sprintf("%s %s %s %s %s %s c\n",
                        self::fmt($tc1x), self::fmt($tc1y),
                        self::fmt($tc2x), self::fmt($tc2y),
                        self::fmt($tex), self::fmt($tey));
                }
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof ClosePath) {
                $out .= "h\n";
            }
        }
        return $out;
    }

    private function renderClipped(SvgClipped $node, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        $clip = $node->clip;

        // Clip-space transform: objectBoundingBox maps the unit box to the
        // child's bounding box; the <clipPath> element transform applies on top.
        // These must affect ONLY the clip geometry (baked into coordinates), not
        // the child - so they are NOT emitted as a cm that would leak onto the
        // child's painting.
        $clipTransform = null;
        if ($clip->units === ClipPathUnits::OBJECT_BOUNDING_BOX) {
            $bbox = BoundingBox::ofNode($node->child);
            if ($bbox->isDegenerate()) {
                return '';
            }
            $clipTransform = SvgMatrix::translate($bbox->x, $bbox->y)->compose(SvgMatrix::scale($bbox->width, $bbox->height));
        }
        if ($clip->transform !== null && !$clip->transform->isIdentity()) {
            $clipTransform = $clipTransform === null ? $clip->transform : $clipTransform->compose($clip->transform);
        }

        $geometry = '';
        foreach ($clip->nodes as $clipNode) {
            $geometry .= $this->emitClipGeometry($clipNode, $clipTransform);
        }
        // Empty clipPath (or only non-contributing content) clips everything
        // away: the child is not rendered.
        if ($geometry === '') {
            return '';
        }

        $terminator = $clip->clipRule === FillRule::EVENODD ? "W* n\n" : "W n\n";
        $child = $this->renderNode($node->child, $registry, $patterns, $ctm);
        if ($child === '') {
            return '';
        }
        return "q\n" . $geometry . $terminator . $child . "Q\n";
    }

    private function renderMasked(SvgMasked $node, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        // The mask applies in the child's own local space at the moment of paint.
        // We honor the SVG semantics by computing the mask bbox and matrix in the
        // unit space of the masked element (the current ctm is the path placed there).
        $bbox = BoundingBox::ofNode($node->child);
        if ($bbox->isDegenerate()) {
            return $this->renderNode($node->child, $registry, $patterns, $ctm);
        }

        $mask = $node->mask;

        // Project the mask region to user space (Form initial coord space).
        // userSpaceOnUse: region is already in user space.
        // objectBoundingBox: region (x,y,w,h) are unit fractions of the child bbox.
        if ($mask->units === MaskUnits::OBJECT_BOUNDING_BOX) {
            $regionX = $bbox->x + $mask->x * $bbox->width;
            $regionY = $bbox->y + $mask->y * $bbox->height;
            $regionW = $mask->width * $bbox->width;
            $regionH = $mask->height * $bbox->height;
        } else {
            $regionX = $mask->x;
            $regionY = $mask->y;
            $regionW = $mask->width;
            $regionH = $mask->height;
        }

        // Sub-render the mask's children with isolated registries.
        // maskContentUnits=objectBoundingBox bakes a unit->user-space cm wrap so
        // child raw coords are interpreted as unit fractions of the bbox; either
        // way the resulting Form content stream is in user space.
        $innerRegistry = new ExtGStateRegistry();
        $innerPatterns = new PatternRegistry();

        $childCtm = SvgMatrix::identity();
        if ($mask->contentUnits === MaskUnits::OBJECT_BOUNDING_BOX) {
            $childCtm = SvgMatrix::translate($bbox->x, $bbox->y)
                ->compose(SvgMatrix::scale($bbox->width, $bbox->height));
        }

        $contentBytes = '';
        if (!$childCtm->isIdentity()) {
            $contentBytes .= "q\n" . self::cmFromMatrix($childCtm) . "\n";
        }
        foreach ($mask->nodes as $maskChild) {
            $contentBytes .= $this->renderNode($maskChild, $innerRegistry, $innerPatterns, $childCtm);
        }
        if (!$childCtm->isIdentity()) {
            $contentBytes .= "Q\n";
        }

        if ($contentBytes === '') {
            // Empty mask -> fall back to no-mask (silent degenerate handling).
            return $this->renderNode($node->child, $registry, $patterns, $ctm);
        }

        // /BBox in user space (Form initial coord space). Form.Matrix = identity
        // because the Form is composed with the CTM at the moment /gs fires; the
        // PDF spec concatenates Form.Matrix onto that CTM, so baking $ctm again
        // would apply it twice and shrink the mask to a dot.
        $embeddedIndex = count($this->embeddedMasks);
        $this->embeddedMasks[] = new EmbeddedMask(
            bbox: [$regionX, $regionY, $regionX + $regionW, $regionY + $regionH],
            matrix: SvgMatrix::identity()->toArray(),
            extGStates: $innerRegistry->entries(),
            patterns: [],
            contentBytes: $contentBytes,
        );

        // Register the ExtGState entry with the smask index. Opacity stays 1.0
        // for the mask wrapper itself (per-paint opacity is handled by the
        // child's own paint state).
        $name = $registry->nameForWithMask(1.0, 1.0, $embeddedIndex);
        $childBytes = $this->renderNode($node->child, $registry, $patterns, $ctm);
        if ($childBytes === '') {
            return '';
        }
        return "q\n/" . $name . " gs\n" . $childBytes . "Q\n";
    }

    /**
     * Emits path-construction operators only (no paint, no terminator) for a
     * clip child. Clip children are emitted as the subpaths of a single path
     * (no q/Q/cm inside the path object, per the PDF content-stream grammar);
     * per-child transforms are baked into the coordinates.
     */
    private function emitClipGeometry(SvgNode $node, ?SvgMatrix $accumulated = null): string
    {
        if ($node instanceof SvgShape) {
            $combined = $this->composeOptional($accumulated, $node->transform());
            return $this->emitGeometry($node, $combined);
        }
        if ($node instanceof SvgGroup) {
            $combined = $this->composeOptional($accumulated, $node->transform);
            $body = '';
            foreach ($node->children as $child) {
                $body .= $this->emitClipGeometry($child, $combined);
            }
            return $body;
        }
        if ($node instanceof SvgClipped) {
            // Nested clip-on-clip is out of scope: use the child's silhouette
            // only (the inner clip is not applied to the outer clip region).
            return $this->emitClipGeometry($node->child, $accumulated);
        }
        // Images and text contribute no clip silhouette.
        return '';
    }

    private function composeOptional(?SvgMatrix $base, ?SvgMatrix $next): ?SvgMatrix
    {
        if ($next === null || $next->isIdentity()) {
            return $base;
        }
        return $base === null ? $next : $base->compose($next);
    }

    private function renderImage(SvgImage $img, ExtGStateRegistry $registry): string
    {
        [$fx, $fy, $fw, $fh] = self::fittedRect(
            $img->x, $img->y, $img->width, $img->height,
            $img->intrinsicWidth, $img->intrinsicHeight, $img->aspectRatio,
        );

        $out = "q\n";
        if ($img->transform !== null && !$img->transform->isIdentity()) {
            $out .= self::cmFromMatrix($img->transform) . "\n";
        }
        $name = $registry->nameFor($img->opacity, $img->opacity);
        if ($name !== '') {
            $out .= '/' . $name . " gs\n";
        }
        if ($img->aspectRatio->align !== Align::NONE && $img->aspectRatio->meetOrSlice === MeetOrSlice::SLICE) {
            $out .= sprintf("%s %s %s %s re\nW n\n", self::fmt($img->x), self::fmt($img->y), self::fmt($img->width), self::fmt($img->height));
        }
        $out .= sprintf("%s 0 0 %s %s %s cm\n", self::fmt($fw), self::fmt(-$fh), self::fmt($fx), self::fmt($fy + $fh));
        $out .= '/Im' . $img->imageIndex . " Do\n";
        $out .= "Q\n";
        return $out;
    }

    private function renderText(SvgText $text, ExtGStateRegistry $registry): string
    {
        if ($text->spans === []) {
            return '';
        }

        $placed = $this->layoutSpans($text->spans);
        $body = '';
        foreach ($placed as [$span, $px, $py, $engine]) {
            $body .= $this->emitSpan($span, $px, $py, $engine, $registry);
        }
        if ($body === '') {
            return '';
        }

        $out = "q\n";
        if ($text->transform !== null && !$text->transform->isIdentity()) {
            $out .= self::cmFromMatrix($text->transform) . "\n";
        }
        $out .= "BT\n" . $body . "ET\n" . "Q\n";
        return $out;
    }

    private function renderTextPath(SvgTextPath $node, ExtGStateRegistry $registry): string
    {
        if ($node->spans === []) {
            return '';
        }
        $poly = PathPolyline::fromCommands($node->pathCommands);
        $pathLen = $poly->length();
        if ($pathLen <= 0.0) {
            return '';
        }

        $totalWidth = 0.0;
        /** @var list<array{0: SvgTextSpan, 1: FontEngine, 2: string, 3: float}> $glyphs */
        $glyphs = [];
        foreach ($node->spans as $span) {
            $engine = $this->engineFor($this->resolveSpanFont($span));
            foreach (mb_str_split($span->text) as $ch) {
                $w = $engine->measure($ch, $span->fontSize);
                $glyphs[] = [$span, $engine, $ch, $w];
                $totalWidth += $w;
            }
        }
        if ($glyphs === []) {
            return '';
        }

        $startOffset = $node->startOffsetIsPercent ? $node->startOffset / 100.0 * $pathLen : $node->startOffset;
        $anchorShift = match ($node->spans[0]->anchor) {
            TextAnchor::MIDDLE => -$totalWidth / 2.0,
            TextAnchor::END => -$totalWidth,
            TextAnchor::START => 0.0,
        };
        $cursor = $startOffset + $anchorShift;

        $body = '';
        $currentSpan = null;
        foreach ($glyphs as [$span, $engine, $ch, $w]) {
            $fill = $span->fill;
            $stroke = $span->stroke;
            $hasFill = $fill !== null;
            $hasStroke = $stroke !== null;
            $center = $cursor + $w / 2.0;
            $cursor += $w;
            if ((!$hasFill && !$hasStroke) || $ch === ' ' || $center < 0.0 || $center > $pathLen) {
                continue;
            }

            $shortName = $engine->registerOn($this->fontRegistry);
            $this->usedFonts[$shortName] = true;
            if ($span !== $currentSpan) {
                $body .= sprintf("/%s %s Tf\n", $shortName, self::fmt($span->fontSize));
                $gs = $registry->nameFor($span->fillOpacity, $span->strokeOpacity);
                if ($gs !== '') {
                    $body .= '/' . $gs . " gs\n";
                }
                if ($hasFill) {
                    $body .= sprintf("%s %s %s rg\n", self::fmt($fill->r), self::fmt($fill->g), self::fmt($fill->b));
                }
                if ($hasStroke) {
                    $body .= sprintf("%s %s %s RG\n", self::fmt($stroke->r), self::fmt($stroke->g), self::fmt($stroke->b));
                    $body .= sprintf("%s w\n", self::fmt($span->strokeWidth));
                }
                $mode = ($hasFill && $hasStroke) ? 2 : ($hasStroke ? 1 : 0);
                $body .= $mode . " Tr\n";
                $currentSpan = $span;
            }

            $pt = $poly->pointAt($center);
            $theta = deg2rad($pt['angleDeg']);
            $cos = cos($theta);
            $sin = sin($theta);
            $body .= sprintf(
                "%s %s %s %s %s %s Tm\n",
                self::fmt($cos), self::fmt($sin), self::fmt($sin), self::fmt(-$cos),
                self::fmt($pt['x']), self::fmt($pt['y']),
            );
            $body .= $engine->encodeShowText($ch);
        }

        if ($body === '') {
            return '';
        }

        $out = "q\n";
        if ($node->transform !== null && !$node->transform->isIdentity()) {
            $out .= self::cmFromMatrix($node->transform) . "\n";
        }
        $out .= "BT\n" . $body . "ET\n" . "Q\n";
        return $out;
    }

    /**
     * Walks spans into absolute (x, y) baseline positions, applying the chunk
     * anchor model: a chunk is a maximal run of spans where only the first
     * carries an absolute x. text-anchor shifts each chunk's start by 0, -w/2,
     * or -w of the chunk's total advance.
     *
     * @param list<SvgTextSpan> $spans
     * @return list<array{0: SvgTextSpan, 1: float, 2: float, 3: FontEngine}>
     */
    private function layoutSpans(array $spans): array
    {
        $placed = [];
        $penX = 0.0;
        $penY = 0.0;
        $n = count($spans);
        $i = 0;
        while ($i < $n) {
            if ($spans[$i]->x !== null) {
                $penX = $spans[$i]->x;
            }
            if ($spans[$i]->y !== null) {
                $penY = $spans[$i]->y;
            }
            $originX = $penX;
            $chunk = [];
            $j = $i;
            while ($j < $n && ($j === $i || $spans[$j]->x === null)) {
                $span = $spans[$j];
                if ($span->y !== null) {
                    $penY = $span->y;
                }
                $penX += $span->dx;
                $penY += $span->dy;
                $engine = $this->engineFor($this->resolveSpanFont($span));
                $width = $engine->measure($span->text, $span->fontSize);
                $chunk[] = [$span, $penX, $penY, $engine];
                $penX += $width;
                $j++;
            }
            $chunkWidth = $penX - $originX;
            $offset = match ($spans[$i]->anchor) {
                TextAnchor::MIDDLE => -$chunkWidth / 2.0,
                TextAnchor::END => -$chunkWidth,
                TextAnchor::START => 0.0,
            };
            foreach ($chunk as [$span, $sx, $sy, $engine]) {
                $placed[] = [$span, $sx + $offset, $sy, $engine];
            }
            $i = $j;
        }
        return $placed;
    }

    private function emitSpan(SvgTextSpan $span, float $px, float $py, FontEngine $engine, ExtGStateRegistry $registry): string
    {
        $hasFill = $span->fill !== null;
        $hasStroke = $span->stroke !== null;
        if (!$hasFill && !$hasStroke) {
            return '';
        }
        if ($span->text === '') {
            return '';
        }
        $shortName = $engine->registerOn($this->fontRegistry);
        $this->usedFonts[$shortName] = true;

        $out = sprintf("/%s %s Tf\n", $shortName, self::fmt($span->fontSize));

        $gs = $registry->nameFor($span->fillOpacity, $span->strokeOpacity);
        if ($gs !== '') {
            $out .= '/' . $gs . " gs\n";
        }

        if ($hasFill) {
            $out .= sprintf("%s %s %s rg\n", self::fmt($span->fill->r), self::fmt($span->fill->g), self::fmt($span->fill->b));
        }
        if ($hasStroke) {
            $out .= sprintf("%s %s %s RG\n", self::fmt($span->stroke->r), self::fmt($span->stroke->g), self::fmt($span->stroke->b));
            $out .= sprintf("%s w\n", self::fmt($span->strokeWidth));
        }

        $mode = ($hasFill && $hasStroke) ? 2 : ($hasStroke ? 1 : 0);
        $out .= $mode . " Tr\n";
        $out .= sprintf("1 0 0 -1 %s %s Tm\n", self::fmt($px), self::fmt($py));
        $out .= $engine->encodeShowText($span->text);
        return $out;
    }

    private function resolveSpanFont(SvgTextSpan $span): Font
    {
        return SvgFontResolver::resolve($span->fontFamily, $span->bold, $span->italic, $this->fontAliases);
    }

    private function engineFor(Font $font): FontEngine
    {
        // resolveSpanFont mints a fresh Font per span, so the resolver's own
        // identity-keyed cache never hits here; this value-keyed cache dedups
        // engines across spans that resolve to the same face.
        $key = $font->isCustom()
            ? ('custom:' . $font->requireCustomAlias() . ($font->isBold() ? 'b' : '') . ($font->isItalic() ? 'i' : ''))
            : $font->pdfName();
        if (isset($this->engines[$key])) {
            return $this->engines[$key];
        }
        // With a resolver present, delegate both font kinds to it; the local
        // StandardFontEngine path covers standard-only SVG with no resolver.
        $engine = $this->fontResolver !== null
            ? $this->fontResolver->resolveEngine($font)
            : new StandardFontEngine($font, $this->metricsRegistry->metricsFor($font));
        return $this->engines[$key] = $engine;
    }

    /**
     * Fits an intrinsic raster size into a viewport rect per preserveAspectRatio.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} fx, fy, fw, fh
     */
    private static function fittedRect(
        float $vx, float $vy, float $vw, float $vh,
        int $iw, int $ih, PreserveAspectRatio $ar,
    ): array {
        if ($ar->align === Align::NONE || $iw <= 0 || $ih <= 0) {
            return [$vx, $vy, $vw, $vh];
        }
        $sx = $vw / $iw;
        $sy = $vh / $ih;
        $s = $ar->meetOrSlice === MeetOrSlice::MEET ? min($sx, $sy) : max($sx, $sy);
        $fw = $iw * $s;
        $fh = $ih * $s;
        $dx = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MIN_Y_MID, Align::X_MIN_Y_MAX => 0.0,
            Align::X_MID_Y_MIN, Align::X_MID_Y_MID, Align::X_MID_Y_MAX => ($vw - $fw) / 2.0,
            default => $vw - $fw,
        };
        $dy = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MID_Y_MIN, Align::X_MAX_Y_MIN => 0.0,
            Align::X_MIN_Y_MID, Align::X_MID_Y_MID, Align::X_MAX_Y_MID => ($vh - $fh) / 2.0,
            default => $vh - $fh,
        };
        return [$vx + $dx, $vy + $dy, $fw, $fh];
    }

    private static function cmFromMatrix(SvgMatrix $m): string
    {
        return sprintf('%s %s %s %s %s %s cm',
            self::fmt($m->a), self::fmt($m->b),
            self::fmt($m->c), self::fmt($m->d),
            self::fmt($m->e), self::fmt($m->f),
        );
    }

    /**
     * Stable, locale-independent number formatting. Trailing zeros and the
     * trailing decimal point are stripped. Critical for byte-identity goldens.
     */
    private static function fmt(float $v): string
    {
        return Format::num($v);
    }
}
