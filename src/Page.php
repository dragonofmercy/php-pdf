<?php

declare(strict_types=1);

namespace PhpPdf;

use PhpPdf\Exception\PdfException;
use PhpPdf\Font\FontRegistry;
use PhpPdf\Font\WinAnsiEncoder;
use PhpPdf\Page\ContentStream;
use PhpPdf\Page\Operators;

/**
 * A single page of the PDF document. Exposes geometric drawing primitives,
 * text rendering via the 12 standard PDF fonts, plus graphics state
 * mutators, transforms, and a save/restore stack. Coordinate system:
 * top-left origin, Y-down, points (1/72 inch).
 */
final class Page
{
    private const float BEZIER_KAPPA = 0.5522847498;

    private readonly ContentStream $stream;

    private ?Font $currentFont = null;
    private ?float $currentSize = null;
    private ?float $customLeading = null;

    /** @var array<string, Font> Fonts used by this page, keyed by PDF canonical name */
    private array $fontsUsed = [];

    public function __construct(
        public readonly float $pageWidth,
        public readonly float $pageHeight,
        private readonly FontRegistry $fontRegistry,
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

    // ----- Primitives -----

    public function line(float $x1, float $y1, float $x2, float $y2): PathOperation
    {
        $this->stream->append(Operators::moveTo($x1, $y1));
        $this->stream->append(Operators::lineTo($x2, $y2));
        return new PathOperation($this->stream);
    }

    public function rect(float $x, float $y, float $w, float $h): PathOperation
    {
        $this->stream->append(Operators::rectangle($x, $y, $w, $h));
        return new PathOperation($this->stream);
    }

    public function circle(float $cx, float $cy, float $r): PathOperation
    {
        $k = self::BEZIER_KAPPA * $r;
        $this->stream->append(Operators::moveTo($cx + $r, $cy));
        $this->stream->append(Operators::curveTo(
            $cx + $r, $cy + $k,
            $cx + $k, $cy + $r,
            $cx, $cy + $r,
        ));
        $this->stream->append(Operators::curveTo(
            $cx - $k, $cy + $r,
            $cx - $r, $cy + $k,
            $cx - $r, $cy,
        ));
        $this->stream->append(Operators::curveTo(
            $cx - $r, $cy - $k,
            $cx - $k, $cy - $r,
            $cx, $cy - $r,
        ));
        $this->stream->append(Operators::curveTo(
            $cx + $k, $cy - $r,
            $cx + $r, $cy - $k,
            $cx + $r, $cy,
        ));
        $this->stream->append(Operators::closePath());
        return new PathOperation($this->stream);
    }

    public function path(): Path
    {
        return new Path($this->stream);
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
        $this->stream->append(Operators::setLineWidth($width));
        return $this;
    }

    /**
     * @param list<float> $pattern dashes and gaps alternating, in points
     */
    public function setDashPattern(array $pattern, float $phase = 0.0): self
    {
        $this->stream->append(Operators::setDashPattern($pattern, $phase));
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
        $this->stream->append(Operators::translate($x, $y));
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
        $this->stream->append(Operators::textMatrix(1, 0, 0, -1, $x, $y));

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
}
