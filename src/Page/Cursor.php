<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Unit;

/**
 * The cell cursor for a page: an (x, y) position in points plus the x at which
 * the current row started (used by NextPosition::NEWLINE). Page owns one
 * instance; cell()/image()/barcode() advance it.
 *
 * @internal
 */
final class Cursor
{
    private ?float $xPt = null;
    private ?float $yPt = null;
    private ?float $lineStartXPt = null;

    public function __construct(private readonly Unit $unit)
    {
    }

    private function toPt(float $value): float
    {
        return $this->unit->toPoints($value);
    }

    private function fromPt(float $value): float
    {
        return $this->unit->fromPoints($value);
    }

    public function getX(): float
    {
        if ($this->xPt === null) {
            throw new PdfException('No cursor set: call setX/setXY or cell() first');
        }
        return $this->fromPt($this->xPt);
    }

    public function getY(): float
    {
        if ($this->yPt === null) {
            throw new PdfException('No cursor set: call setY/setXY or cell() first');
        }
        return $this->fromPt($this->yPt);
    }

    public function setX(float $x): void
    {
        $this->xPt = $this->toPt($x);
        $this->lineStartXPt = $this->xPt;
    }

    public function setY(float $y): void
    {
        $this->yPt = $this->toPt($y);
    }

    public function setXY(float $x, float $y): void
    {
        $this->xPt = $this->toPt($x);
        $this->yPt = $this->toPt($y);
        $this->lineStartXPt = $this->xPt;
    }

    public function xPt(): ?float
    {
        return $this->xPt;
    }

    public function yPt(): ?float
    {
        return $this->yPt;
    }

    public function setLineStartXPt(float $xPt): void
    {
        $this->lineStartXPt = $xPt;
    }

    public function setPositionPt(float $xPt, float $yPt): void
    {
        $this->xPt = $xPt;
        $this->yPt = $yPt;
    }

    public function advance(NextPosition $ln, float $xPt, float $yPt, float $effWidthPt, float $heightPt): void
    {
        $bottomPt = $yPt + $heightPt;
        switch ($ln) {
            case NextPosition::RIGHT:
                $this->xPt = $xPt + $effWidthPt;
                $this->yPt = $yPt;
                break;
            case NextPosition::NEWLINE:
                $this->xPt = $this->lineStartXPt ?? $xPt;
                $this->yPt = $bottomPt;
                break;
            case NextPosition::BELOW:
                $this->xPt = $xPt;
                $this->yPt = $bottomPt;
                break;
            case NextPosition::NONE:
                break;
        }
    }
}
