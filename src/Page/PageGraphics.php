<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\LineCap;
use DragonOfMercy\PhpPdf\LineJoin;
use DragonOfMercy\PhpPdf\Path;
use DragonOfMercy\PhpPdf\PathOperation;
use DragonOfMercy\PhpPdf\Unit;

/**
 * Stateless emitter of graphics operators (primitives, graphics state,
 * transforms, save/restore) into a page content stream. Works in the page's
 * document unit; converts to points before emitting. Holds no mutable state -
 * Page owns one instance and delegates its public drawing methods here.
 *
 * @internal
 */
final class PageGraphics
{
    private const float BEZIER_KAPPA = 0.5522847498;

    public function __construct(
        private readonly ContentStream $stream,
        private readonly Unit $unit,
    ) {
    }

    private function toPt(float $value): float
    {
        return $this->unit->toPoints($value);
    }

    public function line(float $x1, float $y1, float $x2, float $y2): PathOperation
    {
        $this->stream->append(Operators::moveTo($this->toPt($x1), $this->toPt($y1)));
        $this->stream->append(Operators::lineTo($this->toPt($x2), $this->toPt($y2)));
        return new PathOperation($this->stream);
    }

    public function rect(float $x, float $y, float $w, float $h): PathOperation
    {
        $this->stream->append(Operators::rectangle($this->toPt($x), $this->toPt($y), $this->toPt($w), $this->toPt($h)));
        return new PathOperation($this->stream);
    }

    public function circle(float $cx, float $cy, float $r): PathOperation
    {
        $cxPt = $this->toPt($cx);
        $cyPt = $this->toPt($cy);
        $rPt = $this->toPt($r);
        $k = self::BEZIER_KAPPA * $rPt;
        $this->stream->append(Operators::moveTo($cxPt + $rPt, $cyPt));
        $this->stream->append(Operators::curveTo($cxPt + $rPt, $cyPt + $k, $cxPt + $k, $cyPt + $rPt, $cxPt, $cyPt + $rPt));
        $this->stream->append(Operators::curveTo($cxPt - $k, $cyPt + $rPt, $cxPt - $rPt, $cyPt + $k, $cxPt - $rPt, $cyPt));
        $this->stream->append(Operators::curveTo($cxPt - $rPt, $cyPt - $k, $cxPt - $k, $cyPt - $rPt, $cxPt, $cyPt - $rPt));
        $this->stream->append(Operators::curveTo($cxPt + $k, $cyPt - $rPt, $cxPt + $rPt, $cyPt - $k, $cxPt + $rPt, $cyPt));
        $this->stream->append(Operators::closePath());
        return new PathOperation($this->stream);
    }

    public function path(): Path
    {
        return new Path($this->stream, $this->unit);
    }

    public function setStrokeColor(Color $color): void
    {
        $this->stream->append($color->toPdfOperator(stroke: true));
    }

    public function setFillColor(Color $color): void
    {
        $this->stream->append($color->toPdfOperator(stroke: false));
    }

    public function setLineWidth(float $width): void
    {
        $this->stream->append(Operators::setLineWidth($this->toPt($width)));
    }

    /**
     * @param list<float> $pattern dashes and gaps alternating, in the document unit
     */
    public function setDashPattern(array $pattern, float $phase = 0.0): void
    {
        $patternPt = array_map(fn (float $v): float => $this->toPt($v), $pattern);
        $this->stream->append(Operators::setDashPattern($patternPt, $this->toPt($phase)));
    }

    public function setLineCap(LineCap $cap): void
    {
        $this->stream->append(Operators::setLineCap($cap));
    }

    public function setLineJoin(LineJoin $join): void
    {
        $this->stream->append(Operators::setLineJoin($join));
    }

    public function translate(float $x, float $y): void
    {
        $this->stream->append(Operators::translate($this->toPt($x), $this->toPt($y)));
    }

    public function rotate(float $degrees): void
    {
        $this->stream->append(Operators::rotate($degrees));
    }

    public function scale(float $sx, float $sy): void
    {
        $this->stream->append(Operators::scale($sx, $sy));
    }

    public function save(): void
    {
        $this->stream->append(Operators::saveState());
    }

    public function restore(): void
    {
        $this->stream->append(Operators::restoreState());
    }
}
