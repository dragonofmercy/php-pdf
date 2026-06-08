<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Barcode\{Barcode, Orientation, OrientableBarcode, SizedBarcode};
use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\ColumnFill;
use DragonOfMercy\PhpPdf\Page\ColumnLayout;
use DragonOfMercy\PhpPdf\CellResult;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Fit;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Markdown\BoxRenderer;
use DragonOfMercy\PhpPdf\Markdown\BreakMode;
use DragonOfMercy\PhpPdf\Markdown\MarkdownParser;
use DragonOfMercy\PhpPdf\Markdown\MarkdownStyle;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use DragonOfMercy\PhpPdf\Page\CellRenderer;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Page\Cursor;
use DragonOfMercy\PhpPdf\Page\Operators;
use DragonOfMercy\PhpPdf\Page\PageGraphics;
use DragonOfMercy\PhpPdf\Page\TextState;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\TableRenderer;
use DragonOfMercy\PhpPdf\Table\TableResult;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use DragonOfMercy\PhpPdf\Tagging\ObjrRef;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Text\Bidi\BidiProcessor;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

/**
 * A single page of the PDF document. Exposes geometric drawing primitives,
 * text rendering via the 12 standard PDF fonts, plus graphics state
 * mutators, transforms, and a save/restore stack. Coordinate system:
 * top-left origin, Y-down. Coordinates and sizes are in the document's
 * configured Unit (mm by default); font sizes and leading are always in
 * points (typographic convention).
 */
final class Page
{
    /** @internal Tolerance (in points) for the auto-break overflow comparison; absorbs float drift on exact-fit cells. */
    public const float OVERFLOW_EPSILON_PT = 0.0001;

    private readonly ContentStream $stream;
    private readonly PageGraphics $graphics;
    private readonly Cursor $cursor;

    private readonly TextState $textState;
    /** Per-side padding stored in points (canonical internal unit). */
    private CellPadding $cellsPaddingPt;

    /** @var array<string, Font> Fonts used by this page, keyed by PDF canonical name */
    private array $fontsUsed = [];

    /** @var array<string, true> Short names of images this page references */
    private array $imagesUsed = [];

    /** @var list<LinkAnnotation> Link annotations declared via {@see link()}, emitted by Document. */
    private array $linkAnnotations = [];

    /** @var list<FormField> Form fields declared via {@see field()}, emitted by Document. */
    private array $formFields = [];

    private readonly PageMargins $margins;

    private ?int $pageNumber = null;

    /** @var int Per-page marked-content id counter (tagged PDF). */
    private int $mcidCounter = 0;

    /** @var int Zero-based index of this page in the document, set by Document::addPage(). */
    private int $pageIndex = 0;

    private ?TabOrder $tabOrder = null;

    /** @internal Set by Document while a header callback is running; suppresses auto-break recursion. */
    public bool $inHeaderRender = false;

    /** When true, drawing is bracketed as a single /Artifact run and tagging hooks are suppressed. */
    private bool $artifactScope = false;

    private ?float $defaultBorderWidthPt = null;

    public function __construct(
        public readonly float $pageWidth,
        public readonly float $pageHeight,
        private readonly FontRegistry $fontRegistry,
        private readonly MetricsRegistry $metricsRegistry,
        private readonly ImageRegistry $imageRegistry,
        public readonly Unit $unit = Unit::PT,
        ?Font $defaultFont = null,
        ?float $defaultSize = null,
        float|CellPadding|null $defaultCellsPadding = null,
        private readonly ?FontResolver $fontResolver = null,
        ?PageMargins $margins = null,
        private readonly ?Document $document = null,
    ) {
        $this->stream = new ContentStream($pageHeight);
        $this->graphics = new PageGraphics($this->stream, $this->unit);
        $this->cursor = new Cursor($this->unit);
        $this->textState = new TextState(
            $this->metricsRegistry,
            $this->fontResolver,
            $defaultFont,
            $defaultSize,
        );
        $this->cellsPaddingPt = $defaultCellsPadding !== null
            ? $this->paddingToPt($this->normalizePadding($defaultCellsPadding))
            : CellPadding::all(2.0);
        $this->margins = $margins ?? PageMargins::all(0.0);
    }

    /**
     * @internal
     */
    public function contentStream(): ContentStream
    {
        return $this->stream;
    }

    /** @internal Set the zero-based page index; called by Document::addPage(). */
    public function setPageIndex(int $index): void
    {
        $this->pageIndex = $index;
    }

    /** The zero-based index of this page within its document. */
    public function pageIndex(): int
    {
        return $this->pageIndex;
    }

    /** @internal Mint the next per-page marked-content id (tagged PDF). */
    public function nextMcid(): int
    {
        return $this->mcidCounter++;
    }

    /**
     * Runs $body with its content bracketed in /Artifact BMC ... EMC and all
     * structure tagging suppressed for the duration. Re-entrant safe: nested
     * calls do not emit a second bracket and restore the prior flag. When the
     * owning document has tagging off, $body still runs but NO artifact
     * operators are emitted (off-path byte-identity preserved).
     *
     * @param callable():void $body
     */
    public function withArtifactScope(callable $body): void
    {
        $tree = $this->document?->structureTree();
        $wasActive = $this->artifactScope;
        $this->artifactScope = true;
        if ($tree !== null && !$wasActive) {
            $this->stream->beginArtifact();
        }
        try {
            $body();
        } finally {
            if ($tree !== null && !$wasActive) {
                $this->stream->endArtifact();
            }
            $this->artifactScope = $wasActive;
        }
    }

    /** @internal Header/footer rendering and decorative draws check this to suppress tagging. */
    public function isArtifactScope(): bool
    {
        return $this->artifactScope;
    }

    /**
     * @internal Whether real content drawn now should be structure-tagged:
     * a structure tree is active and we are not inside an artifact scope.
     */
    public function shouldTag(): bool
    {
        return $this->document?->structureTree() !== null && !$this->artifactScope;
    }

    /**
     * @internal Snapshot the font-related state so a caller (Document) can
     * bracket a header/footer callback and restore the page exactly as it was
     * before the callback ran. Without this, header callbacks would leak their
     * setFont() into the body that follows (most visibly after an auto-break:
     * the new page is created in regular, the header switches to bold, then
     * the user's cell() inherits bold).
     *
     * @return array{font: ?Font, size: ?float, leading: ?float, engine: ?FontEngine}
     */
    public function captureFontState(): array
    {
        return $this->textState->capture();
    }

    /**
     * @internal Mirror of {@see captureFontState()}.
     *
     * @param array{font: ?Font, size: ?float, leading: ?float, engine: ?FontEngine} $state
     */
    public function restoreFontState(array $state): void
    {
        $this->textState->restore($state);
    }

    /**
     * Page margins inherited from the document, or {@see PageMargins::all(0.0)}
     * when the page was created without a document-level margin (legacy direct
     * construction).
     */
    public function margins(): PageMargins
    {
        return $this->margins;
    }

    /** @internal Owning document, or null for legacy direct construction. */
    public function document(): ?Document
    {
        return $this->document;
    }

    /** @internal Flow cursor, so table() can advance it on the final page after a page break. */
    public function cursor(): Cursor
    {
        return $this->cursor;
    }

    /**
     * Sets the per-page default border line width. Overrides the value
     * configured on the {@see Document}. `null` reverts to the document
     * default for subsequent cell() calls. Initial value is null (delegate
     * to document).
     */
    public function setDefaultBorderWidth(?float $width): self
    {
        if ($width !== null && $width <= 0) {
            throw new PdfException('Default border width must be positive, got ' . $width);
        }
        $this->defaultBorderWidthPt = $width !== null ? $this->toPt($width) : null;
        return $this;
    }

    /**
     * @internal Resolves the effective default border width in points by
     * consulting the page-level override first, then falling back to the
     * document-level default.
     */
    public function resolveDefaultBorderWidthPt(): float
    {
        if ($this->defaultBorderWidthPt !== null) {
            return $this->defaultBorderWidthPt;
        }
        if ($this->document === null) {
            throw new PdfException('Page has no Document and no per-page default border width was set');
        }
        return $this->document->defaultBorderWidthPt();
    }

    public function pageNumber(): int
    {
        if ($this->pageNumber === null) {
            throw new PdfException('Page number is not set');
        }
        return $this->pageNumber;
    }

    /**
     * @internal Called by Document::addPage to assign the page number.
     */
    public function setPageNumber(int $n): void
    {
        $this->pageNumber = $n;
    }

    /**
     * Sets the tab order for this page's annotations and form fields. When set,
     * emits /Tabs /R (ROW), /Tabs /C (COLUMN), or /Tabs /S (STRUCTURE) in the
     * page dictionary. Null (the default) omits the entry, leaving it to the
     * reader.
     */
    public function setTabOrder(?TabOrder $order): self
    {
        $this->tabOrder = $order;
        return $this;
    }

    /**
     * @internal Consumed by PageObjectsBuilder to emit /Tabs when set.
     */
    public function tabOrder(): ?TabOrder
    {
        return $this->tabOrder;
    }

    /**
     * @internal
     * @return list<Font>
     */
    public function fontsUsed(): array
    {
        return array_values($this->fontsUsed);
    }

    /**
     * @internal
     * @return list<string>
     */
    public function imagesUsed(): array
    {
        return array_keys($this->imagesUsed);
    }

    // ----- Primitives -----

    public function line(float $x1, float $y1, float $x2, float $y2): PathOperation
    {
        return $this->graphics->line($x1, $y1, $x2, $y2);
    }

    public function rect(float $x, float $y, float $w, float $h): PathOperation
    {
        return $this->graphics->rect($x, $y, $w, $h);
    }

    public function circle(float $cx, float $cy, float $r): PathOperation
    {
        return $this->graphics->circle($cx, $cy, $r);
    }

    public function path(): Path
    {
        return $this->graphics->path();
    }

    // ----- Graphics state -----

    public function setStrokeColor(Color $color): self
    {
        $this->graphics->setStrokeColor($color);
        return $this;
    }

    public function setFillColor(Color $color): self
    {
        $this->graphics->setFillColor($color);
        return $this;
    }

    public function setLineWidth(float $width): self
    {
        $this->graphics->setLineWidth($width);
        return $this;
    }

    /**
     * @param list<float> $pattern dashes and gaps alternating, in the document unit
     */
    public function setDashPattern(array $pattern, float $phase = 0.0): self
    {
        $this->graphics->setDashPattern($pattern, $phase);
        return $this;
    }

    public function setLineCap(LineCap $cap): self
    {
        $this->graphics->setLineCap($cap);
        return $this;
    }

    public function setLineJoin(LineJoin $join): self
    {
        $this->graphics->setLineJoin($join);
        return $this;
    }

    // ----- Transforms -----

    public function translate(float $x, float $y): self
    {
        $this->graphics->translate($x, $y);
        return $this;
    }

    public function rotate(float $degrees): self
    {
        $this->graphics->rotate($degrees);
        return $this;
    }

    public function scale(float $sx, float $sy): self
    {
        $this->graphics->scale($sx, $sy);
        return $this;
    }

    // ----- State stack -----

    public function save(): self
    {
        $this->graphics->save();
        return $this;
    }

    public function restore(): self
    {
        $this->graphics->restore();
        return $this;
    }

    // ----- Text (Phase 2b) -----

    public function getFont(): Font
    {
        return $this->textState->getFont();
    }

    public function getFontSize(): float
    {
        return $this->textState->getFontSize();
    }

    public function setFont(Font $font, ?float $size = null): self
    {
        $this->textState->setFont($font, $size);
        return $this;
    }

    public function setFontSize(float $size): self
    {
        $this->textState->setFontSize($size);
        return $this;
    }

    public function setLeading(float $leading): self
    {
        $this->textState->setLeading($leading);
        return $this;
    }

    public function text(float $x, float $y, string $text): self
    {
        if ($this->textState->currentFont() === null || $this->textState->currentSize() === null) {
            throw new PdfException('setFont() must be called before text()');
        }
        $engine = $this->textState->activeEngine();

        $shortName = $engine->registerOn($this->fontRegistry);
        $this->fontsUsed[$engine->usageKey()] = $engine->font();

        $size = $this->textState->getFontSize();
        $leading = $this->textState->customLeading() ?? ($size * 1.2);

        $this->stream->append(Operators::beginText());
        $this->stream->append(Operators::setFontAndSize($shortName, $size));
        $this->stream->append(Operators::setTextLeading($leading));
        $this->stream->append(Operators::textMatrix(1, 0, 0, -1, $this->toPt($x), $this->toPt($y)));

        $lines = explode("\n", self::normalizeNewlines($text));
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                $engine->emitShowText($this->stream, $line);
            } else {
                $engine->emitShowTextNextLine($this->stream, $line);
            }
        }

        $this->stream->append(Operators::endText());
        return $this;
    }

    /**
     * Returns the width of the longest line of $text, expressed in the
     * document's unit. Uses the supplied font/size, or the current state set
     * via setFont() if either is null. Empty string returns 0.0.
     */
    public function stringWidth(string $text, ?Font $font = null, ?float $size = null): float
    {
        $resolvedFont = $font ?? $this->textState->currentFont();
        $resolvedSize = $size ?? $this->textState->currentSize();

        if ($resolvedFont === null || $resolvedSize === null) {
            throw new PdfException('No font set: pass $font and $size, or call setFont() first');
        }

        if ($text === '') {
            return 0.0;
        }

        return $this->fromPt($this->textState->measureMaxLineWidthPt($text, $resolvedFont, $resolvedSize));
    }

    /**
     * @internal Measures the width of $text in POINTS for an ARBITRARY font and
     * size, without mutating the page's font state. Mirrors {@see stringWidth()}
     * but stays in points and returns the longest line's width (text may contain
     * newlines). Used by the Markdown renderer, which works internally in points.
     */
    public function measureStringPt(string $text, Font $font, float $sizePt): float
    {
        return $this->textState->measureMaxLineWidthPt($text, $font, $sizePt);
    }

    /**
     * Sets the page-level cells padding. Accepts either a single float (same
     * value all four sides) or a {@see CellPadding} instance for per-side
     * control. Values are interpreted in the document's unit.
     */
    public function setCellsPadding(float|CellPadding $padding): self
    {
        $this->cellsPaddingPt = $this->paddingToPt($this->normalizePadding($padding));
        return $this;
    }

    /**
     * Returns the page-level cells padding as a {@see CellPadding} in the
     * document's unit.
     */
    public function getCellsPadding(): CellPadding
    {
        return $this->paddingFromPt($this->cellsPaddingPt);
    }

    /**
     * Returns the current cell cursor x in the document's unit.
     * Throws if no cursor has been set yet (no prior cell() with `ln`,
     * setX/setXY/setY, etc.).
     */
    public function getX(): float
    {
        return $this->cursor->getX();
    }

    public function getY(): float
    {
        return $this->cursor->getY();
    }

    /**
     * Returns the page width in the document's unit. The {@see $pageWidth}
     * property exposes the same dimension in points for internal serialization.
     */
    public function getPageWidth(): float
    {
        return $this->fromPt($this->pageWidth);
    }

    /**
     * Returns the page height in the document's unit. The {@see $pageHeight}
     * property exposes the same dimension in points for internal serialization.
     */
    public function getPageHeight(): float
    {
        return $this->fromPt($this->pageHeight);
    }

    /**
     * Sets the cell cursor x. Also redefines the row-start anchor used by
     * NextPosition::NEWLINE -- analogous to passing an explicit x to cell().
     */
    public function setX(float $x): self
    {
        $this->cursor->setX($x);
        return $this;
    }

    public function setY(float $y): self
    {
        $this->cursor->setY($y);
        return $this;
    }

    public function setXY(float $x, float $y): self
    {
        $this->cursor->setXY($x, $y);
        return $this;
    }

    public function cell(
        ?float $x = null,
        ?float $y = null,
        ?float $w = null,
        ?float $h = null,
        string $text = '',
        ?Border $border = null,
        ?Color $fill = null,
        ?Color $textColor = null,
        TextAlign $align = TextAlign::LEFT,
        VerticalAlign $verticalAlign = VerticalAlign::TOP,
        Fit $fit = Fit::NONE,
        float|CellPadding|null $padding = null,
        ?Link $link = null,
        ?string $linkAlt = null,
        NextPosition $ln = NextPosition::RIGHT,
        bool $markdown = false,
    ): CellResult {
        // Inside a columns() block, earlier content may have flowed onto a newer
        // page than the one a render callback captured; always draw on the
        // document's current page so nothing lands on a stale page.
        $current = $this->columnRedirectTarget();
        if ($current !== null) {
            return $current->cell($x, $y, $w, $h, $text, $border, $fill, $textColor, $align, $verticalAlign, $fit, $padding, $link, $linkAlt, $ln, $markdown);
        }

        if ($this->textState->currentFont() === null || $this->textState->currentSize() === null) {
            throw new PdfException('setFont() must be called before cell()');
        }
        if ($linkAlt !== null && $link === null) {
            throw new PdfException('cell() linkAlt requires a link');
        }
        if ($link !== null && $markdown) {
            throw new PdfException('cell() link is not supported with markdown: true');
        }
        // Capture the link and its validated rectangle up front: a single non-null
        // check below then narrows the link, width, and height together for the
        // LinkAnnotation constructor without an assert or cast (the column-layout
        // block further down may reassign $w/$h back to nullable).
        $linkRect = null;
        if ($link !== null) {
            if ($w === null || $h === null || $w <= 0.0 || $h <= 0.0) {
                throw new PdfException(sprintf(
                    'cell() with a link requires a positive width and height, got w=%s h=%s',
                    $w === null ? 'null' : self::formatNumber($w),
                    $h === null ? 'null' : self::formatNumber($h),
                ));
            }
            $linkRect = ['link' => $link, 'w' => $w, 'h' => $h];
        }
        if ($markdown && $text !== '') {
            return $this->renderMarkdownCell($x, $y, $w, $h, $text, $border, $fill, $padding, $ln);
        }
        if ($w !== null && $w <= 0) {
            throw new PdfException('Cell width must be positive, got ' . $w);
        }
        if ($h !== null && $h < 0) {
            throw new PdfException('Cell height cannot be negative, got ' . $h);
        }

        // CRLF/CR line endings would otherwise leave a stray \r after the
        // explode-on-\n in the renderer, which WinAnsiEncoder maps to '?'.
        $text = self::normalizeNewlines($text);

        // When inside a columns() block, default x to the active column's left
        // edge and default w to the column width so that callers do not have to
        // hard-code geometry that depends on the layout.
        $columnLayout = $this->document?->columnLayout();
        if ($columnLayout !== null && !$this->inHeaderRender) {
            if ($x === null) {
                $x = $this->fromPt($columnLayout->leftPtForColumn($this->document->columnIndex()));
            }
            if ($w === null && $text !== '') {
                $w = $this->fromPt($columnLayout->widthPt);
            }
        }

        // Column overflow: when inside a columns() block and the cell would
        // exceed the page bottom, flow to the next column (or new page col 0)
        // regardless of whether autoPageBreak is active.
        if ($columnLayout !== null && !$this->inHeaderRender) {
            $colOverflow = $this->maybeColumnOverflow($y, $h, $text, $x, $w, $border, $fill, $textColor, $align, $verticalAlign, $fit, $padding, $link, $linkAlt, $ln);
            if ($colOverflow !== null) {
                return $colOverflow;
            }
        }

        // Auto-page-break: when active and we are not currently rendering a
        // header, check whether this cell would overflow the bottom margin
        // and if so, delegate to a new page.
        $broken = $this->maybeAutoBreak($x, $y, $w, $h, $text, $border, $fill, $textColor, $align, $verticalAlign, $fit, $padding, $link, $linkAlt, $ln);
        if ($broken !== null) {
            return $broken;
        }

        // An explicit x defines a new row anchor for NEWLINE; an omitted x
        // falls back to the cursor maintained by previous cell() calls.
        $xExplicit = $x !== null;
        $x = $this->cursor->resolveX($x, 'Cell');
        $y = $this->cursor->resolveY($y, 'Cell');
        if ($xExplicit) {
            $this->cursor->setLineStartXPt($this->toPt($x));
        }

        $resolvedPaddingPt = $padding !== null
            ? $this->paddingToPt($this->normalizePadding($padding))
            : $this->cellsPaddingPt;

        if ($w === null && $text === '') {
            throw new PdfException('Cell width is required when text is empty');
        }

        $engine = $this->textState->activeEngine();
        $fontShortName = '';
        if ($text !== '') {
            $fontShortName = $engine->registerOn($this->fontRegistry);
            $this->fontsUsed[$engine->usageKey()] = $engine->font();
        }

        // Border width is converted to points before handing the border off to CellRenderer.
        $borderForRenderer = $this->resolveBorderForRenderer($border);

        // Auto-tagging: when a structure tree is active and this cell carries
        // text, bracket just the text-show operators in a <P> marked-content
        // sequence. maybeAutoBreak() above already delegated the whole cell to
        // the destination page when an overflow occurred, so at this point
        // $this IS the page whose content stream receives the show operators;
        // mint the mcid and record the leaf against $this.
        $tree = $this->document?->structureTree();
        $mcid = null;
        $linkElem = null;
        if ($this->shouldTag() && $tree !== null && $text !== '') {
            $linkElem = $link !== null ? $tree->open(StructureType::Link) : null;
            if ($linkElem === null) {
                $tree->open(StructureType::P);
            }
            $mcid = $this->nextMcid();
        }

        // Register the link annotation over the cell box. It is tagged (carrying a
        // /StructParent ordinal and /Contents) only when this cell is actually
        // tagged with text; otherwise it is the plain convenience area link.
        $annot = null;
        if ($linkRect !== null) {
            if ($linkElem !== null) {
                // shouldTag() && $text !== '' is implied by $linkElem !== null,
                // which implies a tagged document. The shared seam allocates the
                // /StructParent ordinal and sets /Contents (= linkAlt or the text).
                $annot = $this->registerTaggedLink($x, $y, $linkRect['w'], $linkRect['h'], $linkRect['link'], $linkAlt ?? $text);
            } else {
                // Plain convenience area link: no structure, no ordinal, no /Contents.
                $annot = new LinkAnnotation(
                    x: $x,
                    y: $y,
                    width: $linkRect['w'],
                    height: $linkRect['h'],
                    link: $linkRect['link'],
                    structParentTagIndex: null,
                    contents: null,
                );
                $this->linkAnnotations[] = $annot;
            }
        }

        $baseDirection = BidiProcessor::resolveBaseDirection($text, $this->document?->baseDirection() ?? Direction::LTR);

        $renderer = new CellRenderer(stream: $this->stream);
        $result = $renderer->render(
            engine: $engine,
            size: $this->textState->getFontSize(),
            customLeading: $this->textState->customLeading(),
            x: $this->toPt($x),
            y: $this->toPt($y),
            w: $w !== null ? $this->toPt($w) : null,
            h: $h !== null ? $this->toPt($h) : null,
            text: $text,
            border: $borderForRenderer,
            fill: $fill,
            textColor: $textColor,
            align: $align,
            verticalAlign: $verticalAlign,
            fit: $fit,
            padding: $resolvedPaddingPt,
            fontShortName: $fontShortName,
            emittingPage: $this,
            markedContentId: $mcid,
            markedContentTag: $link !== null ? 'Link' : 'P',
            artifactDecoration: $this->shouldTag(),
            direction: $baseDirection,
        );

        if ($tree !== null && $mcid !== null) {
            $tree->addMarkedContent($this->pageIndex(), $mcid);
            // A non-null $linkElem implies a tagged link cell, so $annot was built
            // above with a non-null structParentTagIndex.
            if ($linkElem !== null) {
                $linkElem->appendObjr(new ObjrRef($annot, $this->pageIndex()));
            }
            $tree->close();
        }

        $xPt = $this->toPt($x);
        $yPt = $this->toPt($y);
        $this->cursor->advance($ln, $xPt, $yPt, $result->effectiveWidth, $result->height);

        if ($this->document?->columnLayout() !== null) {
            $this->document->recordColumnBottomPt($yPt + $result->height);
        }

        return new CellResult(
            x: $this->fromPt($result->x),
            y: $this->fromPt($result->y),
            height: $this->fromPt($result->height),
            lineCount: $result->lineCount,
            brokenWords: $result->brokenWords,
            textOverflow: $result->textOverflow,
            effectiveWidth: $this->fromPt($result->effectiveWidth),
            page: $this,
        );
    }

    /**
     * Renders flowing Markdown from the cursor (or the given x/y), wrapping at
     * $width and breaking across pages at line granularity when content reaches
     * the bottom margin.
     *
     * x/y default to the current cursor (like {@see cell()}); $width defaults to
     * the page width minus the right margin minus x. $style defaults to
     * {@see MarkdownStyle::default()}.
     *
     * Page breaks: when a {@see Document} is attached AND auto-page-break is on,
     * content that crosses the page bottom limit continues on a freshly added
     * page below the document top margin, carrying the body font/size forward.
     * Otherwise (no document, or auto-break off) the whole Markdown is rendered
     * ATOMICALLY on the current page (it may overflow the page; this is the
     * documented fallback, matching the no-break contract of cell(markdown:)).
     *
     * After rendering, the cursor is advanced on the FINAL page per $ln. The
     * default is BELOW (cursor moves to the block's left edge, just under the
     * content) so consecutive markdown()/cell() calls flow down the page; pass
     * NONE to leave the cursor untouched. Returns the page the method was called
     * on for chaining, regardless of how many pages the content spanned. An
     * empty $markdown is a no-op.
     */
    public function markdown(
        string $markdown,
        ?float $x = null,
        ?float $y = null,
        ?float $width = null,
        ?MarkdownStyle $style = null,
        NextPosition $ln = NextPosition::BELOW,
    ): self {
        // Inside a columns() block, earlier content may have flowed onto a newer
        // page than the one a render callback captured; always draw on the
        // document's current page so nothing lands on a stale page.
        $current = $this->columnRedirectTarget();
        if ($current !== null) {
            return $current->markdown($markdown, $x, $y, $width, $style, $ln);
        }

        if ($this->textState->currentFont() === null || $this->textState->currentSize() === null) {
            throw new PdfException('setFont() must be called before markdown()');
        }
        if ($markdown === '') {
            return $this;
        }

        $x = $this->cursor->resolveX($x, 'Markdown');
        $y = $this->cursor->resolveY($y, 'Markdown');

        $document = $this->document;
        $columnLayout = $document !== null ? $document->columnLayout() : null;
        $columnFlowing = $columnLayout !== null && !$this->inHeaderRender;

        if ($columnFlowing) {
            $startIndex = $document->columnIndex();
            $x = $this->fromPt($columnLayout->leftPtForColumn($startIndex));
            $width = $this->fromPt($columnLayout->widthPt);
        } elseif ($width === null) {
            $width = $this->getPageWidth() - $this->margins->right - $x;
        }

        if ($width <= 0) {
            throw new PdfException('Markdown width must be positive, got ' . $width);
        }

        $style ??= MarkdownStyle::default();
        $ast = MarkdownParser::parse($markdown);
        $renderer = new BoxRenderer();

        $bodyFont = $this->getFont();
        $bodySize = $this->getFontSize();

        // BoxRenderer mutates the page font/fill while drawing; bracket the whole
        // render so the caller's font state survives intact.
        $fontState = $this->captureFontState();
        try {
            $flowing = $document !== null && $document->autoPageBreak() && !$this->inHeaderRender;

            if ($columnFlowing) {
                // Column-FLOW: opening the columns() block is the flow signal;
                // autoPageBreak is not required. Each overflow advances to the
                // next column (same page) or a fresh page's column 0.
                // ($columnLayout is non-null: $columnFlowing = ($columnLayout !== null) && ...)
                // ($document is non-null: $columnLayout came from $document->columnLayout(),
                //  so a non-null $columnLayout implies a non-null $document)
                $stepPt = $columnLayout->stepPt;
                $topMarginY = $this->fromPt($columnLayout->topPt);
                $finalPage = $this;
                $onPageBreak = function () use ($bodyFont, $bodySize, $topMarginY, $startIndex, $stepPt, $document, &$finalPage): array {
                    $page = $this->advanceColumnFlow();
                    $page->setFont($bodyFont, $bodySize);
                    $finalPage = $page;
                    $xShiftPt = ($document->columnIndex() - $startIndex) * $stepPt;
                    return [$page, $topMarginY, $xShiftPt];
                };

                $consumedHeight = $renderer->render($ast, $style, $x, $y, $width, $this, BreakMode::FLOW, false, $onPageBreak);
                $finalStartTop = $finalPage === $this ? $y : $this->fromPt($columnLayout->topPt);
                $this->advanceMarkdownCursor($finalPage, $ln, $x, $y, $width, $consumedHeight, $finalStartTop);
                $document->recordColumnBottomPt($this->toPt($finalStartTop) + $this->toPt($consumedHeight));
            } elseif ($flowing) {
                // FLOW: each break adds a page, re-applies the body font (addPage
                // seeds the document default, which may differ), and continues at
                // the document top margin.
                // ($document is non-null here: $flowing = ($document !== null) && ...)
                $topMarginY = $document->margins()->top;
                $finalPage = $this;
                $onPageBreak = function () use ($bodyFont, $bodySize, $topMarginY, $document, &$finalPage): array {
                    $newPage = $document->addPage();
                    $newPage->setFont($bodyFont, $bodySize);
                    $finalPage = $newPage;
                    return [$newPage, $topMarginY, 0.0];
                };

                $consumedHeight = $renderer->render($ast, $style, $x, $y, $width, $this, BreakMode::FLOW, false, $onPageBreak);

                // Advance the cursor on the final page (where the last line landed).
                $this->advanceMarkdownCursor($finalPage, $ln, $x, $y, $width, $consumedHeight, $finalPage === $this ? $y : $document->margins()->top);
            } else {
                // Fallback: render the whole document atomically on this page.
                $consumedHeight = $renderer->render($ast, $style, $x, $y, $width, $this, BreakMode::ATOMIC);
                $this->advanceMarkdownCursor($this, $ln, $x, $y, $width, $consumedHeight, $y);
            }
        } finally {
            $this->restoreFontState($fontState);
        }

        return $this;
    }

    /**
     * Advances $targetPage's cursor after a markdown render. The block started at
     * $startY (document unit) on $targetPage and consumed $consumedHeight; the
     * cursor moves per $ln exactly as cell() would over that rect.
     */
    private function advanceMarkdownCursor(
        Page $targetPage,
        NextPosition $ln,
        float $x,
        float $y,
        float $width,
        float $consumedHeight,
        float $startY,
    ): void {
        $xPt = $this->toPt($x);
        $startYPt = $this->toPt($startY);
        $widthPt = $this->toPt($width);
        $heightPt = $this->toPt($consumedHeight);
        $targetPage->cursor->advance($ln, $xPt, $startYPt, $widthPt, $heightPt);
    }

    /**
     * Markdown variant of {@see cell()}: parses $text as Markdown and renders it
     * (ATOMIC, never page-breaking the content itself) into the cell's inner box,
     * auto-growing the cell height to the consumed content height plus vertical
     * padding. Border/fill are drawn at the final rect and the cursor advances per
     * $ln, exactly as the plain-text path. $text is guaranteed non-empty by the
     * caller. Font state is bracketed so the BoxRenderer's font/fill mutations do
     * not leak into subsequent calls.
     */
    private function renderMarkdownCell(
        ?float $x,
        ?float $y,
        ?float $w,
        ?float $h,
        string $text,
        ?Border $border,
        ?Color $fill,
        float|CellPadding|null $padding,
        NextPosition $ln,
    ): CellResult {
        if ($w === null) {
            throw new PdfException('Markdown cell requires an explicit width (w)');
        }

        $resolvedPaddingPt = $padding !== null
            ? $this->paddingToPt($this->normalizePadding($padding))
            : $this->cellsPaddingPt;
        $padTopBottomPt = $resolvedPaddingPt->top + $resolvedPaddingPt->bottom;
        $padLeftRightPt = $resolvedPaddingPt->left + $resolvedPaddingPt->right;

        $wPt = $this->toPt($w);
        $innerWidthPt = max(0.0, $wPt - $padLeftRightPt);

        $style = MarkdownStyle::default();
        $ast = MarkdownParser::parse($text);
        $renderer = new BoxRenderer();

        $fontState = $this->captureFontState();
        try {
            // Measuring pass: identical layout/cursor math, no emission. Used to
            // size the cell so fill/border can be drawn UNDER the content.
            $contentHeightPt = $this->toPt($renderer->render(
                $ast,
                $style,
                $this->fromPt(0.0),
                $this->fromPt(0.0),
                $this->fromPt($innerWidthPt),
                $this,
                BreakMode::ATOMIC,
                measureOnly: true,
            ));

            $computedHeightPt = $contentHeightPt + $padTopBottomPt;
            $cellHeightPt = $h !== null ? max($this->toPt($h), $computedHeightPt) : $computedHeightPt;

            // Auto-break against the measured height, mirroring the text path: a
            // markdown cell that would overflow the bottom margin is re-rendered on
            // a fresh page (suppressing recursion via inHeaderRender there).
            $broken = $this->maybeAutoBreakMarkdown($x, $y, $w, $this->fromPt($cellHeightPt), $text, $border, $fill, $padding, $ln);
            if ($broken !== null) {
                return $broken;
            }

            $xExplicit = $x !== null;
            $x = $this->cursor->resolveX($x, 'Cell');
            $y = $this->cursor->resolveY($y, 'Cell');
            if ($xExplicit) {
                $this->cursor->setLineStartXPt($this->toPt($x));
            }

            $xPt = $this->toPt($x);
            $yPt = $this->toPt($y);

            // Reuse the text path's fill+border drawing by rendering an empty cell
            // at the final rect (explicit width and height), so the emitted bytes
            // match a plain cell's fill/border exactly.
            $borderForRenderer = $this->resolveBorderForRenderer($border);
            $cellRenderer = new CellRenderer(stream: $this->stream);
            $cellRenderer->render(
                engine: $this->textState->activeEngine(),
                size: $this->textState->getFontSize(),
                customLeading: $this->textState->customLeading(),
                x: $xPt,
                y: $yPt,
                w: $wPt,
                h: $cellHeightPt,
                text: '',
                border: $borderForRenderer,
                fill: $fill,
                textColor: null,
                align: TextAlign::LEFT,
                verticalAlign: VerticalAlign::TOP,
                fit: Fit::NONE,
                padding: $resolvedPaddingPt,
                fontShortName: '',
                emittingPage: $this,
            );

            // Draw the markdown content into the inner box.
            $innerXPt = $xPt + $resolvedPaddingPt->left;
            $innerYPt = $yPt + $resolvedPaddingPt->top;
            $renderer->render(
                $ast,
                $style,
                $this->fromPt($innerXPt),
                $this->fromPt($innerYPt),
                $this->fromPt($innerWidthPt),
                $this,
                BreakMode::ATOMIC,
            );
        } finally {
            $this->restoreFontState($fontState);
        }

        $this->cursor->advance($ln, $xPt, $yPt, $wPt, $cellHeightPt);

        return new CellResult(
            x: $this->fromPt($xPt + $wPt),
            y: $this->fromPt($yPt + $cellHeightPt),
            height: $this->fromPt($cellHeightPt),
            lineCount: 0,
            brokenWords: 0,
            textOverflow: false,
            effectiveWidth: $this->fromPt($wPt),
            page: $this,
        );
    }

    /**
     * Auto-page-break for {@see renderMarkdownCell()}. Mirrors {@see maybeAutoBreak()}
     * but uses the already-measured markdown cell height as the overflow estimate
     * and re-renders via the markdown path on the new page. Returns null when no
     * break is needed.
     */
    private function maybeAutoBreakMarkdown(
        ?float $x,
        ?float $y,
        ?float $w,
        float $cellHeight,
        string $text,
        ?Border $border,
        ?Color $fill,
        float|CellPadding|null $padding,
        NextPosition $ln,
    ): ?CellResult {
        if ($this->document === null
            || !$this->document->autoPageBreak()
            || $this->inHeaderRender
        ) {
            return null;
        }

        $resolvedYPt = $y !== null
            ? $this->toPt($y)
            : ($this->cursor->yPt() ?? null);

        if ($resolvedYPt === null) {
            return null;
        }

        $bottomLimitPt = $this->pageHeight - $this->toPt($this->document->margins()->bottom);
        if ($resolvedYPt + $this->toPt($cellHeight) <= $bottomLimitPt + self::OVERFLOW_EPSILON_PT) {
            return null;
        }

        $newPage = $this->document->addPage();
        $newPage->inHeaderRender = true;
        try {
            return $newPage->cell(
                x: $x,
                y: null,
                w: $w,
                h: null,
                text: $text,
                border: $border,
                fill: $fill,
                padding: $padding,
                ln: $ln,
                markdown: true,
            );
        } finally {
            $newPage->inHeaderRender = false;
        }
    }

    /**
     * @internal Wrapped text block height in points for a given inner width.
     * The active font/size must be set by the caller (the table renderer sets
     * the column/cell font before calling). Returns lineCount * leading.
     */
    public function tableTextHeightPt(string $text, float $innerWidthPt): float
    {
        $engine = $this->textState->activeEngine();
        $sizePt = $this->textState->getFontSize();
        $leading = $this->textState->customLeading() ?? ($sizePt * 1.2);
        $renderer = new CellRenderer(stream: $this->stream);
        $wrap = $renderer->wrapText(self::normalizeNewlines($text), $innerWidthPt, $engine, $sizePt);

        return max(1, count($wrap->lines)) * $leading;
    }

    /**
     * @internal Draw one already-measured table cell box: border + fill + text.
     * No auto-page-break, no flow-cursor advance. All coordinates in points.
     */
    public function drawTableCell(
        float $xPt,
        float $yPt,
        float $wPt,
        float $hPt,
        string $text,
        ?Border $border,
        ?Color $fill,
        ?Color $textColor,
        TextAlign $align,
        VerticalAlign $verticalAlign,
        CellPadding $paddingPt,
        ?int $markedContentId = null,
        string $markedContentTag = 'P',
    ): void {
        $text = self::normalizeNewlines($text);

        $engine = $this->textState->activeEngine();
        $fontShortName = '';
        if ($text !== '') {
            $fontShortName = $engine->registerOn($this->fontRegistry);
            $this->fontsUsed[$engine->usageKey()] = $engine->font();
        }

        $renderer = new CellRenderer(stream: $this->stream);
        $renderer->render(
            engine: $engine,
            size: $this->textState->getFontSize(),
            customLeading: $this->textState->customLeading(),
            x: $xPt,
            y: $yPt,
            w: $wPt,
            h: $hPt,
            text: $text,
            border: $this->resolveBorderForRenderer($border),
            fill: $fill,
            textColor: $textColor,
            align: $align,
            verticalAlign: $verticalAlign,
            fit: Fit::NONE,
            padding: $paddingPt,
            fontShortName: $fontShortName,
            emittingPage: $this,
            markedContentId: $markedContentId,
            markedContentTag: $markedContentTag,
            artifactDecoration: $this->shouldTag(),
        );
    }

    /**
     * @internal Place an image inside a table cell box. Draws only the image
     * (border/fill are drawn by a prior drawTableCell call with empty text).
     * The image is sized per Page::image() rules ($reqWPt/$reqHPt), clamped to
     * the cell inner width preserving aspect, then aligned in the inner box.
     * Returns the drawn image height in points.
     */
    public function drawTableImage(
        float $xPt,
        float $yPt,
        float $wPt,
        float $hPt,
        Image $image,
        ?float $reqWPt,
        ?float $reqHPt,
        TextAlign $align,
        VerticalAlign $verticalAlign,
        CellPadding $paddingPt,
    ): float {
        $innerX = $xPt + $paddingPt->left;
        $innerY = $yPt + $paddingPt->top;
        $innerW = max(0.0, $wPt - $paddingPt->left - $paddingPt->right);
        $innerH = max(0.0, $hPt - $paddingPt->top - $paddingPt->bottom);

        [$drawW, $drawH] = $this->resolveTableImageSizePt($image, $reqWPt, $reqHPt, $innerW);

        // Horizontal alignment inside the inner box.
        $offsetX = match ($align) {
            TextAlign::LEFT, TextAlign::JUSTIFY => 0.0,
            TextAlign::CENTER => ($innerW - $drawW) / 2.0,
            TextAlign::RIGHT => $innerW - $drawW,
        };
        // Vertical alignment inside the inner box.
        $offsetY = match ($verticalAlign) {
            VerticalAlign::TOP => 0.0,
            VerticalAlign::MIDDLE => ($innerH - $drawH) / 2.0,
            VerticalAlign::BOTTOM => $innerH - $drawH,
        };

        if ($drawW <= 0.0 || $drawH <= 0.0) {
            return 0.0;
        }

        $this->image(
            $image,
            x: $this->fromPt($innerX + max(0.0, $offsetX)),
            y: $this->fromPt($innerY + max(0.0, $offsetY)),
            w: $this->fromPt($drawW),
            h: $this->fromPt($drawH),
        );

        return $drawH;
    }

    /**
     * @internal Compute the drawn image size in points: apply Page::image() w/h
     * rules, then clamp to the cell inner width preserving aspect.
     *
     * @return array{0: float, 1: float}
     */
    public function resolveTableImageSizePt(Image $image, ?float $reqWPt, ?float $reqHPt, float $innerWPt): array
    {
        [$w, $h] = self::sizeImagePt($image, $reqWPt, $reqHPt);

        if ($innerWPt > 0 && $w > $innerWPt) {
            $h = $h * $innerWPt / $w;
            $w = $innerWPt;
        }

        return [$w, $h];
    }

    /**
     * Resolve the drawn image size in points from optional point-space requested
     * width/height, applying the four-branch (w+h / w-only / h-only / neither)
     * intrinsic-aspect rules shared by {@see image()} and
     * {@see resolveTableImageSizePt()}. Intrinsic dimensions are pixel counts;
     * the library treats one pixel as one PDF point.
     *
     * @return array{0: float, 1: float}
     */
    private static function sizeImagePt(Image $image, ?float $reqWPt, ?float $reqHPt): array
    {
        $intrinsicW = (float) $image->width;
        $intrinsicH = (float) $image->height;

        if ($reqWPt !== null && $reqHPt !== null) {
            $w = $reqWPt;
            $h = $reqHPt;
        } elseif ($reqWPt !== null) {
            $w = $reqWPt;
            $h = $reqWPt * $intrinsicH / $intrinsicW;
        } elseif ($reqHPt !== null) {
            $h = $reqHPt;
            $w = $reqHPt * $intrinsicW / $intrinsicH;
        } else {
            $w = $intrinsicW;
            $h = $intrinsicH;
        }

        return [$w, $h];
    }

    /**
     * Places a raster or vector image with its top-left corner at (x, y) in the
     * document unit. Omitted x/y fall back to the current cursor position.
     *
     * Dimension rules: both w and h given -> forced; one omitted -> derived from
     * the aspect ratio of the other; both omitted -> intrinsic size at 72 DPI.
     *
     * After drawing, the cursor advances according to $ln. The default is RIGHT
     * (x moves to the right edge of the image, y unchanged), which matches the
     * legacy behaviour. Use NEWLINE to start a new line below, BELOW to move the
     * cursor beneath the image, or NONE to leave the cursor untouched.
     *
     * When the document has tagging on, $alt sets the alternate text on the
     * generated Figure element, and $decorative draws the image as a pure
     * /Artifact (no Figure, no marked content) for purely decorative imagery.
     * Passing both $alt and $decorative is an error.
     *
     * Pass $link to make the image a clickable hyperlink. When tagging is active
     * the annotation is a tagged <Link> wrapping the <Figure> (OBJR + /StructParent
     * + /Contents); otherwise a plain area annotation is used. $linkAlt sets
     * /Contents on the annotation, falling back to $alt, then empty.
     */
    public function image(
        string|Image $image,
        ?float $x = null,
        ?float $y = null,
        ?float $w = null,
        ?float $h = null,
        ?string $alt = null,
        bool $decorative = false,
        ?Link $link = null,
        ?string $linkAlt = null,
        NextPosition $ln = NextPosition::RIGHT,
    ): self {
        if ($this->document?->columnLayout() !== null) {
            throw new PdfException('image() is not supported inside a columns() block in this version');
        }
        if ($alt !== null && $decorative) {
            throw new PdfException('image() cannot be both decorative and have alt text');
        }
        if ($linkAlt !== null && $link === null) {
            throw new PdfException('image() linkAlt requires a link');
        }
        if ($link !== null && $decorative) {
            throw new PdfException('image() link is not supported with decorative: true');
        }
        if ($w !== null && $w <= 0.0) {
            throw new PdfException("Image width must be positive, got {$w}");
        }
        if ($h !== null && $h <= 0.0) {
            throw new PdfException("Image height must be positive, got {$h}");
        }

        $xExplicit = $x !== null;
        $x = $this->cursor->resolveX($x, 'Image');
        $y = $this->cursor->resolveY($y, 'Image');
        if ($xExplicit) {
            $this->cursor->setLineStartXPt($this->toPt($x));
        }

        [$shortName, $resolved] = $this->imageRegistry->register($image);
        [$effWPt, $effHPt] = self::sizeImagePt(
            $resolved,
            $w !== null ? $this->toPt($w) : null,
            $h !== null ? $this->toPt($h) : null,
        );

        $xPt = $this->toPt($x);
        $yPt = $this->toPt($y);

        if ($decorative) {
            // Decorative: draw inside an artifact run, no Figure, no MCID.
            $this->withArtifactScope(function () use ($shortName, $effWPt, $effHPt, $xPt, $yPt): void {
                $this->drawImageXObject($shortName, $effWPt, $effHPt, $xPt, $yPt, null);
            });
        } else {
            $tree = $this->document?->structureTree();
            $linkElem = null;
            $mcid = null;
            if ($tree !== null && !$this->artifactScope) {
                if ($link !== null) {
                    $linkElem = $tree->open(StructureType::Link);
                }
                $mcid = $this->nextMcid();
                $figure = $tree->open(StructureType::Figure);
                if ($alt !== null) {
                    $figure->setAlt($alt);
                }
            }

            // Register the link annotation over the image box: tagged (OBJR +
            // /StructParent + /Contents) when the figure is tagged, otherwise a
            // plain area link (unchanged in non-UA; rejected by the UA guard,
            // like any untagged link).
            if ($link !== null) {
                $linkWidth = $this->fromPt($effWPt);
                $linkHeight = $this->fromPt($effHPt);
                if ($linkElem !== null) {
                    $annot = $this->registerTaggedLink($x, $y, $linkWidth, $linkHeight, $link, $linkAlt ?? $alt ?? '');
                    $linkElem->appendObjr(new ObjrRef($annot, $this->pageIndex()));
                } else {
                    $this->link($x, $y, $linkWidth, $linkHeight, $link);
                }
            }

            $this->drawImageXObject($shortName, $effWPt, $effHPt, $xPt, $yPt, $mcid);

            if ($tree !== null && $mcid !== null) {
                $tree->addMarkedContent($this->pageIndex(), $mcid);
                $tree->close(); // Figure
                if ($linkElem !== null) {
                    $tree->close(); // Link
                }
            }
        }

        $this->imagesUsed[$shortName] = true;
        // Advance the cursor over the image's bounding box per $ln, mirroring
        // cell()/barcode(). The default RIGHT reproduces the legacy behaviour
        // (right edge, same top y) so existing callers are unaffected.
        $this->cursor->advance($ln, $xPt, $yPt, $effWPt, $effHPt);
        return $this;
    }

    /**
     * Emits the image-drawing operators (q / optional BDC / cm / Do / optional
     * EMC / Q). When $mcid is non-null, the XObject invocation is wrapped in a
     * /Figure marked-content sequence; otherwise the operators are emitted bare
     * (the caller supplies any artifact bracketing). Shared by the tagged and
     * decorative paths so the draw is not duplicated.
     */
    private function drawImageXObject(string $shortName, float $effWPt, float $effHPt, float $xPt, float $yPt, ?int $mcid): void
    {
        $this->stream->append(Operators::saveState());
        if ($mcid !== null) {
            $this->stream->beginMarkedContent(StructureType::Figure->value, $mcid);
        }
        $this->stream->append(Operators::concatMatrix(
            $effWPt, 0, 0, -$effHPt, $xPt, $yPt + $effHPt,
        ));
        $this->stream->append(Operators::invokeXObject($shortName));
        if ($mcid !== null) {
            $this->stream->endMarkedContent();
        }
        $this->stream->append(Operators::restoreState());
    }

    /**
     * Draws a barcode (1D or 2D) at the given top-left position in the page
     * unit. The barcode itself manages its rendering, including a save/restore
     * of the graphics state, so this call does not alter the page's current
     * fill color, font, etc.
     *
     * For 1D barcodes (EAN-13, EAN-8, UPC-A, Code 39, Code 93, Code 128, ITF)
     * `h` is required; for square 2D barcodes (QR, Aztec, DataMatrix) it is
     * optional (defaults to `w`). PDF417 is rectangular: `h` is optional and
     * unconstrained (need not equal `w`); when null it is derived from the
     * symbol's row count.
     *
     * `w` may be omitted when the barcode implements {@see SizedBarcode} and
     * has had {@see SizedBarcode::withModuleSize()} called on it: the width is
     * then taken from {@see SizedBarcode::intrinsicWidth()}. Otherwise `w` is
     * required.
     *
     * After drawing, the cursor advances to the right edge of the barcode's
     * visual bounding box: by `w` for a horizontal barcode, or by `h` for a
     * vertical OrientableBarcode (whose visual width is the bar height).
     */
    public function barcode(
        Barcode $code,
        ?float $x = null,
        ?float $y = null,
        ?float $w = null,
        ?float $h = null,
        NextPosition $ln = NextPosition::NONE,
    ): self {
        if ($this->document?->columnLayout() !== null) {
            throw new PdfException('barcode() is not supported inside a columns() block in this version');
        }
        if ($w === null && $code instanceof SizedBarcode) {
            $w = $code->intrinsicWidth();
        }
        if ($w === null) {
            throw new PdfException('Barcode width is required (pass w or set a module size via withModuleSize())');
        }
        $xExplicit = $x !== null;
        $x = $this->cursor->resolveX($x, 'Barcode');
        $y = $this->cursor->resolveY($y, 'Barcode');
        if ($xExplicit) {
            $this->cursor->setLineStartXPt($this->toPt($x));
        }
        $code->draw($this, $x, $y, $w, $h);
        // Cursor advance mirrors cell()'s NextPosition, but over the barcode's
        // VISUAL bounding box. A vertical 1D code is rotated a quarter turn, so
        // its visual width is h and its visual height is w; otherwise width is w
        // and height is h. A square 2D code may omit h, in which case its
        // rendered height equals w.
        $resolvedHPt = $this->toPt($h ?? $w);
        $wPt = $this->toPt($w);
        $isVertical = $code instanceof OrientableBarcode && $code->orientation() === Orientation::Vertical;
        $visualWidthPt = $isVertical ? $resolvedHPt : $wPt;
        $visualHeightPt = $isVertical ? $wPt : $resolvedHPt;
        $this->cursor->advance($ln, $this->toPt($x), $this->toPt($y), $visualWidthPt, $visualHeightPt);
        return $this;
    }

    /**
     * Render a data table starting at (x, y) within $width. Reuses the cell /
     * image pipeline per cell; paginates with header repeat when the document
     * has auto-page-break on. Returns a {@see TableResult} with the final anchor
     * and span metrics (the final page may differ from this one after a break).
     *
     * @param list<Column> $columns
     * @param iterable<array<string, mixed>> $rows
     */
    public function table(
        array $columns,
        iterable $rows,
        ?float $x = null,
        ?float $y = null,
        ?float $width = null,
        ?TableStyle $style = null,
        NextPosition $ln = NextPosition::BELOW,
    ): TableResult {
        if ($this->document?->columnLayout() !== null) {
            throw new PdfException('table() is not supported inside a columns() block in this version');
        }
        if ($this->textState->currentFont() === null || $this->textState->currentSize() === null) {
            throw new PdfException('setFont() must be called before table()');
        }
        $xExplicit = $x !== null;
        $x = $this->cursor->resolveX($x, 'Table');
        $y = $this->cursor->resolveY($y, 'Table');
        if ($xExplicit) {
            $this->cursor->setLineStartXPt($this->toPt($x));
        }
        $width ??= $this->fromPt($this->pageWidth) - $this->margins()->right - $x;

        $tree = $this->document?->structureTree();
        if ($tree !== null && !$this->artifactScope) {
            $tree->open(StructureType::Table);
        }
        $renderer = new TableRenderer($this, $columns, $rows, $style ?? TableStyle::default());
        [$finalYPt, $rowCount, $pageCount, $finalPage] = $renderer->render($this->toPt($x), $this->toPt($y), $this->toPt($width));
        if ($tree !== null && !$this->artifactScope) {
            $tree->close();
        }

        $finalPage->cursor()->advance($ln, $finalPage->toPt($x), $finalYPt, $finalPage->toPt($width), 0.0);

        return new TableResult(
            x: $x,
            y: $finalPage->fromPt($finalYPt),
            rowCount: $rowCount,
            pageCount: $pageCount,
            page: $finalPage,
        );
    }

    /**
     * Flows cell() and markdown() content across $count equal-width columns.
     * Inside $render, omitted x/width default to the current column; content
     * fills a column to the bottom margin, then the next column, then a new page
     * (SEQUENTIAL). After the block, full-width flow resumes with the cursor below
     * the lowest column content on the final page.
     */
    public function columns(
        int $count,
        float $gap = 0.0,
        ColumnFill $fill = ColumnFill::SEQUENTIAL,
        ?callable $render = null,
    ): self {
        if ($this->document === null) {
            throw new PdfException('columns() requires the page to belong to a Document');
        }
        if ($this->document->columnLayout() !== null) {
            throw new PdfException('columns() cannot be nested');
        }
        if ($fill === ColumnFill::BALANCED) {
            throw new PdfException('ColumnFill::BALANCED is not yet implemented');
        }
        if ($render === null) {
            return $this;
        }

        $contentWidthPt = $this->pageWidth
            - $this->toPt($this->margins->left)
            - $this->toPt($this->margins->right);
        $layout = ColumnLayout::compute(
            $count,
            $this->toPt($gap),
            $this->toPt($this->margins->left),
            $this->toPt($this->margins->top),
            $contentWidthPt,
            $fill,
        );

        $this->document->beginColumns($layout);
        $this->setXY($this->fromPt($layout->leftPtForColumn(0)), $this->fromPt($layout->topPt));
        try {
            $render($this->document->getCurrentPage());
        } finally {
            $bottomPt = $this->document->columnPageBottomPt();
            $finalPage = $this->document->getCurrentPage();
            $this->document->endColumns();
            $finalPage->setXY($this->margins->left, $this->fromPt($bottomPt));
        }

        return $this;
    }

    /**
     * Forces flow to continue at the top of the next column (or the next page when
     * already in the last column). Only valid inside a columns() block.
     */
    public function columnBreak(): self
    {
        if ($this->document === null || $this->document->columnLayout() === null) {
            throw new PdfException('columnBreak() is only valid inside a columns() block');
        }
        $this->advanceColumnFlow();
        return $this;
    }

    /**
     * The page draws should be redirected to: inside a columns() block, content
     * may have flowed onto a newer page than the one a render callback captured.
     * Returns the document's current page when it differs from this one, else null.
     */
    private function columnRedirectTarget(): ?Page
    {
        if ($this->document?->columnLayout() === null) {
            return null;
        }
        $current = $this->document->getCurrentPage();
        return $current !== $this ? $current : null;
    }

    /**
     * Advances column flow: to the next column on the same page, or - when in the
     * last column - to a new page's column 0. Assumes an active column layout.
     * @internal
     */
    private function advanceColumnFlow(): Page
    {
        $doc = $this->document;
        assert($doc !== null);
        $layout = $doc->columnLayout();
        assert($layout !== null);

        $index = $doc->columnIndex();
        if ($index < $layout->count - 1) {
            $next = $index + 1;
            $doc->setColumnIndex($next);
            $page = $doc->getCurrentPage();
            $page->setXY($this->fromPt($layout->leftPtForColumn($next)), $this->fromPt($layout->topPt));
            return $page;
        }
        $newPage = $doc->addPage();
        if ($this->textState->currentFont() !== null) {
            $newPage->setFont($this->getFont(), $this->getFontSize());
        }
        return $newPage;
    }

    /**
     * @internal
     */
    public function metricsRegistry(): MetricsRegistry
    {
        return $this->metricsRegistry;
    }

    /**
     * @internal
     *
     * @return array{ascent: float, descent: float, capHeight: float, xHeight: float}
     */
    public function activeFontMetricsAtPt(float $sizePt): array
    {
        $engine = $this->textState->activeEngine();
        return [
            'ascent' => $engine->ascentAt($sizePt),
            'descent' => $engine->descentAt($sizePt),
            'capHeight' => $engine->capHeightAt($sizePt),
            'xHeight' => $engine->xHeightAt($sizePt),
        ];
    }

    /**
     * Declares a clickable link annotation covering the rectangle `(x, y,
     * width, height)` in the document's user unit (top-down Y, same as
     * `cell()` / `text()`). The `Link` payload picks the action:
     * - `Link::url('https://...')` opens the URL in the user's browser.
     * - `Link::destination(Destination::page($n))` jumps to another page.
     *
     * Coordinates outside the page's MediaBox are accepted as-is (a link can
     * legally extend beyond the visible area; PDF readers clip silently).
     */
    public function link(float $x, float $y, float $width, float $height, Link $link): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new PdfException(sprintf(
                'Link annotation width and height must be positive, got w=%s h=%s',
                self::formatNumber($width),
                self::formatNumber($height),
            ));
        }
        $this->linkAnnotations[] = new LinkAnnotation(x: $x, y: $y, width: $width, height: $height, link: $link);
        return $this;
    }

    /**
     * Registers a tagged hyperlink annotation for one link rectangle and returns
     * it so the caller can wrap it in an /OBJR under the owning <Link> structure
     * element. Mirrors the tagged branch of {@see cell()}: a /StructParent ordinal
     * is allocated and /Contents is set. Coordinates are in the page unit. Used by
     * the Markdown renderer and by image() for tagged links.
     *
     * @internal
     */
    public function registerTaggedLink(float $x, float $y, float $width, float $height, Link $link, string $contents): LinkAnnotation
    {
        if ($width <= 0 || $height <= 0) {
            throw new PdfException(sprintf(
                'Link annotation width and height must be positive, got w=%s h=%s',
                self::formatNumber($width),
                self::formatNumber($height),
            ));
        }
        $document = $this->document;
        if ($document === null) {
            throw new PdfException('Tagged Markdown link requires a document context');
        }
        $annot = new LinkAnnotation(
            x: $x,
            y: $y,
            width: $width,
            height: $height,
            link: $link,
            structParentTagIndex: $document->nextLinkStructParentIndex(),
            contents: $contents,
        );
        $this->linkAnnotations[] = $annot;

        return $annot;
    }

    /**
     * @return list<LinkAnnotation>
     * @internal Consumed by Document::buildPagesFontsImages() to emit /Annots.
     */
    public function getLinkAnnotations(): array
    {
        return $this->linkAnnotations;
    }

    /**
     * Attaches a form field VO ({@see \DragonOfMercy\PhpPdf\Form\TextField},
     * {@see \DragonOfMercy\PhpPdf\Form\Checkbox}, {@see \DragonOfMercy\PhpPdf\Form\Radio},
     * {@see \DragonOfMercy\PhpPdf\Form\Combobox}, {@see \DragonOfMercy\PhpPdf\Form\Listbox})
     * to this page. Field coordinates and dimensions are already validated
     * by the VO constructor. The field will be emitted as a PDF widget
     * annotation and registered in the document's /AcroForm at
     * Document::output() time.
     */
    public function field(FormField $field): self
    {
        $this->formFields[] = $field;
        return $this;
    }

    /**
     * @return list<FormField>
     * @internal Consumed by Document::buildPagesFontsImages() to emit /Annots
     *           and the /AcroForm dictionary.
     */
    public function getFormFields(): array
    {
        return $this->formFields;
    }

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }

    /**
     * Converts the border's width to points for CellRenderer. When the border
     * is null or empty, it is returned as-is. When the border carries a null
     * width, the page/document default is used.
     */
    private function resolveBorderForRenderer(?Border $border): ?Border
    {
        if ($border === null || $border->isEmpty()) {
            return $border;
        }
        $widthPt = $border->width !== null
            ? $this->toPt($border->width)
            : $this->resolveDefaultBorderWidthPt();
        return $border->withWidth($widthPt);
    }

    /**
     * When auto-page-break is active and this cell would overflow the bottom
     * margin, creates a new page and renders the cell there, returning its
     * result. Returns null when no break is needed (caller renders normally).
     * $text must already be newline-normalized.
     */
    private function maybeAutoBreak(
        ?float $x,
        ?float $y,
        ?float $w,
        ?float $h,
        string $text,
        ?Border $border,
        ?Color $fill,
        ?Color $textColor,
        TextAlign $align,
        VerticalAlign $verticalAlign,
        Fit $fit,
        float|CellPadding|null $padding,
        ?Link $link,
        ?string $linkAlt,
        NextPosition $ln,
    ): ?CellResult {
        if ($this->document === null
            || !$this->document->autoPageBreak()
            || $this->inHeaderRender
        ) {
            return null;
        }

        $resolvedYPt = $y !== null
            ? $this->toPt($y)
            : ($this->cursor->yPt() ?? null);

        if ($resolvedYPt === null) {
            return null;
        }

        $estimatedHeightPt = $h !== null
            ? $this->toPt($h)
            : $this->estimateCellHeightPt(
                $text,
                $this->textState->getFontSize(),
                $this->textState->customLeading(),
            );

        $bottomLimitPt = $this->pageHeight - $this->toPt(
            $this->document->margins()->bottom,
        );

        if ($resolvedYPt + $estimatedHeightPt <= $bottomLimitPt + self::OVERFLOW_EPSILON_PT) {
            return null;
        }

        $newPage = $this->document->addPage();
        // Suppress auto-break on the new page for this one emission, so that a cell
        // larger than the drawable area does not recurse infinitely.
        $newPage->inHeaderRender = true;
        try {
            return $newPage->cell(
                x: $x,
                y: null,
                w: $w,
                h: $h,
                text: $text,
                border: $border,
                fill: $fill,
                textColor: $textColor,
                align: $align,
                verticalAlign: $verticalAlign,
                fit: $fit,
                padding: $padding,
                link: $link,
                linkAlt: $linkAlt,
                ln: $ln,
            );
        } finally {
            $newPage->inHeaderRender = false;
        }
    }

    /**
     * Column overflow check for cells inside a columns() block. Runs regardless
     * of autoPageBreak. When the cell would exceed the page bottom, advances to
     * the next column (or a new page's column 0) and renders there, returning
     * the result. Returns null when no column overflow is needed. Assumes an
     * active column layout and !$this->inHeaderRender at the call site.
     * $text must already be newline-normalized.
     */
    private function maybeColumnOverflow(
        ?float $y,
        ?float $h,
        string $text,
        ?float $x,
        ?float $w,
        ?Border $border,
        ?Color $fill,
        ?Color $textColor,
        TextAlign $align,
        VerticalAlign $verticalAlign,
        Fit $fit,
        float|CellPadding|null $padding,
        ?Link $link,
        ?string $linkAlt,
        NextPosition $ln,
    ): ?CellResult {
        $resolvedYPt = $y !== null
            ? $this->toPt($y)
            : ($this->cursor->yPt() ?? null);

        if ($resolvedYPt === null) {
            return null;
        }

        $estimatedHeightPt = $h !== null
            ? $this->toPt($h)
            : $this->estimateCellHeightPt(
                $text,
                $this->textState->getFontSize(),
                $this->textState->customLeading(),
            );

        $bottomLimitPt = $this->pageHeight - $this->toPt($this->document?->margins()->bottom ?? 0.0);

        if ($resolvedYPt + $estimatedHeightPt <= $bottomLimitPt + self::OVERFLOW_EPSILON_PT) {
            return null;
        }

        // A cell that is already at the very top of the column is drawn there
        // even if it is taller than the column, to avoid silently skipping a
        // column (mirrors the same guard in FlowBreaker).
        $layout = $this->document?->columnLayout();
        if ($layout !== null && $resolvedYPt <= $layout->topPt + self::OVERFLOW_EPSILON_PT) {
            return null;
        }

        // Advance to the next column (or new page col 0) and re-render there.
        // Pass x: null so the target cell picks up the new column's left edge;
        // pass inHeaderRender=true as a one-shot recursion guard (a cell taller
        // than a full column draws where it lands instead of looping).
        $targetPage = $this->advanceColumnFlow();
        $targetPage->inHeaderRender = true;
        try {
            return $targetPage->cell(
                x: null,
                y: null,
                w: $w,
                h: $h,
                text: $text,
                border: $border,
                fill: $fill,
                textColor: $textColor,
                align: $align,
                verticalAlign: $verticalAlign,
                fit: $fit,
                padding: $padding,
                link: $link,
                linkAlt: $linkAlt,
                ln: $ln,
            );
        } finally {
            $targetPage->inHeaderRender = false;
        }
    }

    /**
     * Upper-bound height estimate used by auto-break before rendering. Ignores
     * wrapping (counts only explicit newlines); the tradeoff is documented in
     * the Phase 6 spec: a few lines of overflow on wrapped text is acceptable
     * to keep the per-cell check O(1).
     */
    private function estimateCellHeightPt(string $text, float $sizePt, ?float $customLeading): float
    {
        if ($text === '' || $sizePt <= 0.0) {
            return 0.0;
        }
        $engine = $this->textState->activeEngine();
        $lineCount = substr_count(self::normalizeNewlines($text), "\n") + 1;
        $effectiveLeading = $customLeading ?? ($sizePt * 1.2);
        $descentAbs = abs($engine->descentAt($sizePt));
        $padTopBottomPt = $this->cellsPaddingPt->top + $this->cellsPaddingPt->bottom;
        return $sizePt + $descentAbs + ($lineCount - 1) * $effectiveLeading + $padTopBottomPt;
    }

    /** @internal Convert a value from the document unit to points. */
    public function toPt(float $value): float
    {
        return $this->unit->toPoints($value);
    }

    /** @internal Convert a value from points to the document unit. */
    public function fromPt(float $value): float
    {
        return $this->unit->fromPoints($value);
    }

    /**
     * Folds CRLF and lone CR to LF so callers do not have to split on multiple
     * line terminators. A stray \r left in a line would render as an unmappable
     * glyph (substituted to '?' or .notdef depending on the active encoding).
     */
    private static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function normalizePadding(float|CellPadding $padding): CellPadding
    {
        return $padding instanceof CellPadding ? $padding : CellPadding::all((float) $padding);
    }

    /** @internal Convert a four-sided {@see CellPadding} from the document unit to points. */
    public function paddingToPt(CellPadding $p): CellPadding
    {
        return new CellPadding(
            top: $this->toPt($p->top),
            right: $this->toPt($p->right),
            bottom: $this->toPt($p->bottom),
            left: $this->toPt($p->left),
        );
    }

    private function paddingFromPt(CellPadding $p): CellPadding
    {
        return new CellPadding(
            top: $this->fromPt($p->top),
            right: $this->fromPt($p->right),
            bottom: $this->fromPt($p->bottom),
            left: $this->fromPt($p->left),
        );
    }
}
