<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Barcode\Barcode;
use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\CellResult;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Fit;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Page\CellRenderer;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Page\Operators;
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
    private const float BEZIER_KAPPA = 0.5522847498;

    private readonly ContentStream $stream;

    private ?Font $currentFont = null;
    private ?float $currentSize = null;
    private ?float $customLeading = null;
    /** Per-side padding stored in points (canonical internal unit). */
    private CellPadding $cellsPaddingPt;

    /** Cell cursor (in pt). Populated by cell() when ln updates the position. */
    private ?float $cursorXPt = null;
    private ?float $cursorYPt = null;
    /** x of the start of the current row of cells (in pt), used by NEWLINE. */
    private ?float $lineStartXPt = null;

    /** @var array<string, Font> Fonts used by this page, keyed by PDF canonical name */
    private array $fontsUsed = [];

    /** @var array<string, true> Short names of images this page references */
    private array $imagesUsed = [];

    private ?FontEngine $currentFontEngine = null;

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
    ) {
        $this->stream = new ContentStream($pageHeight);
        if (($defaultFont === null) !== ($defaultSize === null)) {
            throw new PdfException('Page default font requires both font and size, or neither');
        }
        if ($defaultFont !== null && $defaultSize !== null) {
            if ($defaultSize <= 0) {
                throw new PdfException('Default font size must be positive, got ' . $defaultSize);
            }
            $this->currentFont = $defaultFont;
            $this->currentSize = $defaultSize;
        }
        $this->cellsPaddingPt = $defaultCellsPadding !== null
            ? $this->paddingToPt($this->normalizePadding($defaultCellsPadding))
            : CellPadding::all(2.0);

        if ($this->currentFont !== null) {
            if ($this->currentFont->isCustom() && $this->fontResolver === null) {
                throw new PdfException('Page received a custom Font as default but no FontResolver from Document');
            }
            if ($this->fontResolver !== null) {
                $this->currentFontEngine = $this->fontResolver->resolveEngine($this->currentFont);
            }
        }
    }

    /**
     * @internal
     */
    public function contentStream(): ContentStream
    {
        return $this->stream;
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
        $this->stream->append(Operators::moveTo($this->toPt($x1), $this->toPt($y1)));
        $this->stream->append(Operators::lineTo($this->toPt($x2), $this->toPt($y2)));
        return new PathOperation($this->stream);
    }

    public function rect(float $x, float $y, float $w, float $h): PathOperation
    {
        $this->stream->append(Operators::rectangle(
            $this->toPt($x),
            $this->toPt($y),
            $this->toPt($w),
            $this->toPt($h),
        ));
        return new PathOperation($this->stream);
    }

    public function circle(float $cx, float $cy, float $r): PathOperation
    {
        $cxPt = $this->toPt($cx);
        $cyPt = $this->toPt($cy);
        $rPt = $this->toPt($r);
        $k = self::BEZIER_KAPPA * $rPt;
        $this->stream->append(Operators::moveTo($cxPt + $rPt, $cyPt));
        $this->stream->append(Operators::curveTo(
            $cxPt + $rPt, $cyPt + $k,
            $cxPt + $k, $cyPt + $rPt,
            $cxPt, $cyPt + $rPt,
        ));
        $this->stream->append(Operators::curveTo(
            $cxPt - $k, $cyPt + $rPt,
            $cxPt - $rPt, $cyPt + $k,
            $cxPt - $rPt, $cyPt,
        ));
        $this->stream->append(Operators::curveTo(
            $cxPt - $rPt, $cyPt - $k,
            $cxPt - $k, $cyPt - $rPt,
            $cxPt, $cyPt - $rPt,
        ));
        $this->stream->append(Operators::curveTo(
            $cxPt + $k, $cyPt - $rPt,
            $cxPt + $rPt, $cyPt - $k,
            $cxPt + $rPt, $cyPt,
        ));
        $this->stream->append(Operators::closePath());
        return new PathOperation($this->stream);
    }

    public function path(): Path
    {
        return new Path($this->stream, $this->unit);
    }

    // ----- Graphics state -----

    public function setStrokeColor(Color $color): self
    {
        $this->stream->append($color->toPdfOperator(stroke: true));
        return $this;
    }

    public function setFillColor(Color $color): self
    {
        $this->stream->append($color->toPdfOperator(stroke: false));
        return $this;
    }

    public function setLineWidth(float $width): self
    {
        $this->stream->append(Operators::setLineWidth($this->toPt($width)));
        return $this;
    }

    /**
     * @param list<float> $pattern dashes and gaps alternating, in the document unit
     */
    public function setDashPattern(array $pattern, float $phase = 0.0): self
    {
        $patternPt = array_map(fn (float $v): float => $this->toPt($v), $pattern);
        $this->stream->append(Operators::setDashPattern($patternPt, $this->toPt($phase)));
        return $this;
    }

    public function setLineCap(LineCap $cap): self
    {
        $this->stream->append(Operators::setLineCap($cap));
        return $this;
    }

    public function setLineJoin(LineJoin $join): self
    {
        $this->stream->append(Operators::setLineJoin($join));
        return $this;
    }

    // ----- Transforms -----

    public function translate(float $x, float $y): self
    {
        $this->stream->append(Operators::translate($this->toPt($x), $this->toPt($y)));
        return $this;
    }

    public function rotate(float $degrees): self
    {
        $this->stream->append(Operators::rotate($degrees));
        return $this;
    }

    public function scale(float $sx, float $sy): self
    {
        $this->stream->append(Operators::scale($sx, $sy));
        return $this;
    }

    // ----- State stack -----

    public function save(): self
    {
        $this->stream->append(Operators::saveState());
        return $this;
    }

    public function restore(): self
    {
        $this->stream->append(Operators::restoreState());
        return $this;
    }

    // ----- Text (Phase 2b) -----

    public function getFont(): Font
    {
        if ($this->currentFont === null) {
            throw new PdfException('No font set: call setFont() first');
        }
        return $this->currentFont;
    }

    public function getFontSize(): float
    {
        if ($this->currentSize === null) {
            throw new PdfException('No font set: call setFont() first');
        }
        return $this->currentSize;
    }

    public function setFont(Font $font, ?float $size = null): self
    {
        if ($size === null) {
            if ($this->currentSize === null) {
                throw new PdfException('Font size is required when no font has been set previously');
            }
            $size = $this->currentSize;
        } elseif ($size <= 0) {
            throw new PdfException('Font size must be positive, got ' . $size);
        }
        if ($font->isCustom() && $this->fontResolver === null) {
            throw new PdfException(
                "Cannot use custom font '" . ($font->customAlias() ?? '') . "': "
                . 'Call Document::registerFontFamily() first.',
            );
        }
        if ($this->fontResolver !== null) {
            $this->currentFontEngine = $this->fontResolver->resolveEngine($font);
        } else {
            $this->currentFontEngine = null;
        }
        $this->currentFont = $font;
        $this->currentSize = $size;
        $this->customLeading = null;
        return $this;
    }

    public function setLeading(float $leading): self
    {
        $this->customLeading = $leading;
        return $this;
    }

    public function text(float $x, float $y, string $text): self
    {
        if ($this->currentFont === null || $this->currentSize === null) {
            throw new PdfException('setFont() must be called before text()');
        }
        $engine = $this->currentFontEngine ?? new StandardFontEngine(
            $this->currentFont,
            $this->metricsRegistry->metricsFor($this->currentFont),
        );

        $shortName = $engine->registerOn($this->fontRegistry);
        $this->fontsUsed[$engine->usageKey()] = $engine->font();

        $size = $this->currentSize;
        $leading = $this->customLeading ?? ($size * 1.2);

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
        $resolvedFont = $font ?? $this->currentFont;
        $resolvedSize = $size ?? $this->currentSize;

        if ($resolvedFont === null || $resolvedSize === null) {
            throw new PdfException('No font set: pass $font and $size, or call setFont() first');
        }

        if ($text === '') {
            return 0.0;
        }

        if ($resolvedFont->isCustom() && $this->fontResolver === null) {
            throw new PdfException('Cannot measure custom Font without a registered family');
        }

        $engine = $resolvedFont === $this->currentFont && $this->currentFontEngine !== null
            ? $this->currentFontEngine
            : ($this->fontResolver !== null
                ? $this->fontResolver->resolveEngine($resolvedFont)
                : new StandardFontEngine($resolvedFont, $this->metricsRegistry->metricsFor($resolvedFont)));

        $maxWidthPt = 0.0;
        foreach (explode("\n", self::normalizeNewlines($text)) as $line) {
            $w = $engine->measure($line, $resolvedSize);
            if ($w > $maxWidthPt) {
                $maxWidthPt = $w;
            }
        }
        return $this->fromPt($maxWidthPt);
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
        if ($this->cursorXPt === null) {
            throw new PdfException('No cursor set: call setX/setXY or cell() first');
        }
        return $this->fromPt($this->cursorXPt);
    }

    public function getY(): float
    {
        if ($this->cursorYPt === null) {
            throw new PdfException('No cursor set: call setY/setXY or cell() first');
        }
        return $this->fromPt($this->cursorYPt);
    }

    /**
     * Sets the cell cursor x. Also redefines the row-start anchor used by
     * NextPosition::NEWLINE -- analogous to passing an explicit x to cell().
     */
    public function setX(float $x): self
    {
        $this->cursorXPt = $this->toPt($x);
        $this->lineStartXPt = $this->cursorXPt;
        return $this;
    }

    public function setY(float $y): self
    {
        $this->cursorYPt = $this->toPt($y);
        return $this;
    }

    public function setXY(float $x, float $y): self
    {
        $this->cursorXPt = $this->toPt($x);
        $this->cursorYPt = $this->toPt($y);
        $this->lineStartXPt = $this->cursorXPt;
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
        ?NextPosition $ln = null,
    ): CellResult {
        if ($this->currentFont === null || $this->currentSize === null) {
            throw new PdfException('setFont() must be called before cell()');
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

        // An explicit x defines a new row anchor for NEWLINE; an omitted x
        // falls back to the cursor maintained by previous cell() calls.
        $xExplicit = $x !== null;
        if ($x === null) {
            if ($this->cursorXPt === null) {
                throw new PdfException('Cell x is required: no cursor set yet');
            }
            $x = $this->fromPt($this->cursorXPt);
        }
        if ($y === null) {
            if ($this->cursorYPt === null) {
                throw new PdfException('Cell y is required: no cursor set yet');
            }
            $y = $this->fromPt($this->cursorYPt);
        }
        if ($xExplicit) {
            $this->lineStartXPt = $this->toPt($x);
        }

        $resolvedPaddingPt = $padding !== null
            ? $this->paddingToPt($this->normalizePadding($padding))
            : $this->cellsPaddingPt;

        if ($w === null) {
            if ($text === '') {
                throw new PdfException('Cell width is required when text is empty');
            }
            $w = $this->stringWidth($text)
                + $this->fromPt($resolvedPaddingPt->left + $resolvedPaddingPt->right);
        }

        $engine = $this->currentFontEngine ?? new StandardFontEngine(
            $this->currentFont,
            $this->metricsRegistry->metricsFor($this->currentFont),
        );
        $fontShortName = '';
        if ($text !== '') {
            $fontShortName = $engine->registerOn($this->fontRegistry);
            $this->fontsUsed[$engine->usageKey()] = $engine->font();
        }

        // Border width is supplied in the document unit; CellRenderer works
        // entirely in points, so convert before handing the border off.
        $borderForRenderer = $border !== null
            ? $border->withWidth($this->toPt($border->width))
            : null;

        $renderer = new CellRenderer(stream: $this->stream);
        $result = $renderer->render(
            engine: $engine,
            size: $this->currentSize,
            customLeading: $this->customLeading,
            x: $this->toPt($x),
            y: $this->toPt($y),
            w: $this->toPt($w),
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
        );

        if ($ln !== null) {
            $xPt = $this->toPt($x);
            $yPt = $this->toPt($y);
            $bottomPt = $yPt + $result->height;
            switch ($ln) {
                case NextPosition::RIGHT:
                    $this->cursorXPt = $xPt + $this->toPt($w);
                    $this->cursorYPt = $yPt;
                    break;
                case NextPosition::NEWLINE:
                    $this->cursorXPt = $this->lineStartXPt ?? $xPt;
                    $this->cursorYPt = $bottomPt;
                    break;
                case NextPosition::BELOW:
                    $this->cursorXPt = $xPt;
                    $this->cursorYPt = $bottomPt;
                    break;
            }
        }

        return new CellResult(
            x: $this->fromPt($result->x),
            y: $this->fromPt($result->y),
            height: $this->fromPt($result->height),
            lineCount: $result->lineCount,
            brokenWords: $result->brokenWords,
            textOverflow: $result->textOverflow,
        );
    }

    public function image(
        string|Image $image,
        float $x,
        float $y,
        ?float $w = null,
        ?float $h = null,
    ): self {
        if ($w !== null && $w <= 0.0) {
            throw new PdfException("Image width must be positive, got {$w}");
        }
        if ($h !== null && $h <= 0.0) {
            throw new PdfException("Image height must be positive, got {$h}");
        }

        [$shortName, $resolved] = $this->imageRegistry->register($image);
        // Intrinsic image dimensions are pixel counts; the library treats one
        // pixel as one PDF point when neither width nor height is supplied.
        $intrinsicWPt = (float) $resolved->width;
        $intrinsicHPt = (float) $resolved->height;

        if ($w !== null && $h !== null) {
            $effWPt = $this->toPt($w);
            $effHPt = $this->toPt($h);
        } elseif ($w !== null) {
            $effWPt = $this->toPt($w);
            $effHPt = $effWPt * $intrinsicHPt / $intrinsicWPt;
        } elseif ($h !== null) {
            $effHPt = $this->toPt($h);
            $effWPt = $effHPt * $intrinsicWPt / $intrinsicHPt;
        } else {
            $effWPt = $intrinsicWPt;
            $effHPt = $intrinsicHPt;
        }

        $xPt = $this->toPt($x);
        $yPt = $this->toPt($y);

        $this->stream->append(Operators::saveState());
        $this->stream->append(Operators::concatMatrix(
            $effWPt, 0, 0, -$effHPt, $xPt, $yPt + $effHPt,
        ));
        $this->stream->append(Operators::invokeXObject($shortName));
        $this->stream->append(Operators::restoreState());

        $this->imagesUsed[$shortName] = true;
        return $this;
    }

    /**
     * Draws a barcode (1D or QR) at the given top-left position in the page
     * unit. The barcode itself manages its rendering, including a save/restore
     * of the graphics state, so this call does not alter the page's current
     * fill color, font, etc.
     *
     * For 1D barcodes (EAN-13, EAN-8, Code 128) `h` is required; for QR it is
     * optional (defaults to `w`, since QR is square).
     */
    public function barcode(Barcode $code, float $x, float $y, float $w, ?float $h = null): self
    {
        $code->draw($this, $x, $y, $w, $h);
        return $this;
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
        if ($this->currentFont === null || $this->currentSize === null) {
            throw new PdfException('No active font on this page');
        }
        $engine = $this->currentFontEngine ?? new StandardFontEngine(
            $this->currentFont,
            $this->metricsRegistry->metricsFor($this->currentFont),
        );
        return [
            'ascent' => $engine->ascentAt($sizePt),
            'descent' => $engine->descentAt($sizePt),
            'capHeight' => $engine->capHeightAt($sizePt),
            'xHeight' => $engine->xHeightAt($sizePt),
        ];
    }

    private function toPt(float $value): float
    {
        return $this->unit->toPoints($value);
    }

    private function fromPt(float $value): float
    {
        return $this->unit->fromPoints($value);
    }

    /**
     * Folds CRLF and lone CR to LF so explode("\n", ...) does not leave a
     * trailing \r on the previous paragraph -- on the standard-font path
     * that \r would render as '?' (WinAnsi mapping for unknown control
     * bytes), and on the custom-TTF path it would render as GID 0 (.notdef).
     */
    private static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function normalizePadding(float|CellPadding $padding): CellPadding
    {
        return $padding instanceof CellPadding ? $padding : CellPadding::all((float) $padding);
    }

    private function paddingToPt(CellPadding $p): CellPadding
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
