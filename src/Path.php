<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Page\Operators;

/**
 * Multi-operation path builder returned by `Page::path()`. Buffers path
 * construction operators until a terminal call (stroke/fill/strokeAndFill)
 * flushes them to the ContentStream followed by the paint operator.
 *
 * Coordinates are accepted in the document's Unit and converted to points
 * internally.
 */
final class Path
{
    private string $buffer = '';

    public function __construct(
        private readonly ContentStream $stream,
        private readonly Unit $unit = Unit::PT,
    ) {}

    public function moveTo(float $x, float $y): self
    {
        $this->buffer .= Operators::moveTo($this->toPt($x), $this->toPt($y));
        return $this;
    }

    public function lineTo(float $x, float $y): self
    {
        $this->buffer .= Operators::lineTo($this->toPt($x), $this->toPt($y));
        return $this;
    }

    public function curveTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): self
    {
        $this->buffer .= Operators::curveTo(
            $this->toPt($c1x),
            $this->toPt($c1y),
            $this->toPt($c2x),
            $this->toPt($c2y),
            $this->toPt($x),
            $this->toPt($y),
        );
        return $this;
    }

    public function close(): self
    {
        $this->buffer .= Operators::closePath();
        return $this;
    }

    public function stroke(): void
    {
        $this->stream->append($this->buffer . Operators::stroke());
        $this->buffer = '';
    }

    public function fill(): void
    {
        $this->stream->append($this->buffer . Operators::fill());
        $this->buffer = '';
    }

    public function strokeAndFill(): void
    {
        $this->stream->append($this->buffer . Operators::fillStroke());
        $this->buffer = '';
    }

    private function toPt(float $value): float
    {
        return $this->unit->toPoints($value);
    }
}
