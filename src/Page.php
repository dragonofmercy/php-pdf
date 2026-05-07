<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\CellResult;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Fit;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
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
    /** Padding stored in points (internal canonical unit). */
    private float $cellsPaddingPt = 2.0;

    /** @var array<string, Font> Fonts used by this page, keyed by PDF canonical name */
    private array $fontsUsed = [];

    /** @var array<string, true> Short names of images this page references */
    private array $imagesUsed = [];

    public function __construct(
        public readonly float $pageWidth,
        public readonly float $pageHeight,
        private readonly FontRegistry $fontRegistry,
        private readonly MetricsRegistry $metricsRegistry,
        private readonly ImageRegistry $imageRegistry,
        public readonly Unit $unit = Unit::PT,
    ) {
        $this->stream = new ContentStream($pageHeight);
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

    public function setFont(Font $font, float $size): self
    {
        if ($size <= 0) {
            throw new PdfException('Font size must be positive, got ' . $size);
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

        $this->fontsUsed[$this->currentFont->pdfName()] = $this->currentFont;
        $shortName = $this->fontRegistry->shortName($this->currentFont);
        $size = $this->currentSize;
        $leading = $this->customLeading ?? ($size * 1.2);

        $this->stream->append(Operators::beginText());
        $this->stream->append(Operators::setFontAndSize($shortName, $size));
        $this->stream->append(Operators::setTextLeading($leading));
        $this->stream->append(Operators::textMatrix(1, 0, 0, -1, $this->toPt($x), $this->toPt($y)));

        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            $encoded = WinAnsiEncoder::encode($line);
            if ($index === 0) {
                $this->stream->append(Operators::showText($encoded));
            } else {
                $this->stream->append(Operators::showTextNextLine($encoded));
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

        $metrics = $this->metricsRegistry->metricsFor($resolvedFont);
        $maxWidth = 0.0;
        foreach (explode("\n", $text) as $line) {
            $encoded = WinAnsiEncoder::encode($line);
            $width = $metrics->stringWidth($encoded, $resolvedSize);
            if ($width > $maxWidth) {
                $maxWidth = $width;
            }
        }
        return $this->fromPt($maxWidth);
    }

    public function setCellsPadding(float $padding): self
    {
        if ($padding < 0) {
            throw new PdfException('Padding cannot be negative, got ' . $padding);
        }
        $this->cellsPaddingPt = $this->toPt($padding);
        return $this;
    }

    public function getCellsPadding(): float
    {
        return $this->fromPt($this->cellsPaddingPt);
    }

    public function cell(
        float $x,
        float $y,
        float $w,
        ?float $h = null,
        string $text = '',
        ?Border $border = null,
        ?Color $fill = null,
        ?Color $textColor = null,
        TextAlign $align = TextAlign::LEFT,
        VerticalAlign $verticalAlign = VerticalAlign::TOP,
        Fit $fit = Fit::NONE,
        ?float $padding = null,
    ): CellResult {
        if ($this->currentFont === null || $this->currentSize === null) {
            throw new PdfException('setFont() must be called before cell()');
        }
        if ($w <= 0) {
            throw new PdfException('Cell width must be positive, got ' . $w);
        }
        if ($h !== null && $h < 0) {
            throw new PdfException('Cell height cannot be negative, got ' . $h);
        }
        if ($padding !== null && $padding < 0) {
            throw new PdfException('Cell padding cannot be negative, got ' . $padding);
        }
        $resolvedPaddingPt = $padding !== null ? $this->toPt($padding) : $this->cellsPaddingPt;

        $fontShortName = '';
        if ($text !== '') {
            $this->fontsUsed[$this->currentFont->pdfName()] = $this->currentFont;
            $fontShortName = $this->fontRegistry->shortName($this->currentFont);
        }

        $renderer = new CellRenderer(stream: $this->stream, metrics: $this->metricsRegistry);
        $result = $renderer->render(
            font: $this->currentFont,
            size: $this->currentSize,
            customLeading: $this->customLeading,
            x: $this->toPt($x),
            y: $this->toPt($y),
            w: $this->toPt($w),
            h: $h !== null ? $this->toPt($h) : null,
            text: $text,
            border: $border,
            fill: $fill,
            textColor: $textColor,
            align: $align,
            verticalAlign: $verticalAlign,
            fit: $fit,
            padding: $resolvedPaddingPt,
            fontShortName: $fontShortName,
        );

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
     * @internal
     */
    public function metricsRegistry(): MetricsRegistry
    {
        return $this->metricsRegistry;
    }

    private function toPt(float $value): float
    {
        return $this->unit->toPoints($value);
    }

    private function fromPt(float $value): float
    {
        return $this->unit->fromPoints($value);
    }
}
