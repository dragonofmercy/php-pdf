<?php

declare(strict_types=1);

namespace PhpPdf;

use PhpPdf\Page\ContentStream;
use PhpPdf\Page\Operators;

/**
 * Multi-operation path builder returned by `Page::path()`. Buffers path
 * construction operators until a terminal call (stroke/fill/strokeAndFill)
 * flushes them to the ContentStream followed by the paint operator.
 */
final class Path
{
    private string $buffer = '';

    public function __construct(private readonly ContentStream $stream) {}

    public function moveTo(float $x, float $y): self
    {
        $this->buffer .= Operators::moveTo($x, $y);
        return $this;
    }

    public function lineTo(float $x, float $y): self
    {
        $this->buffer .= Operators::lineTo($x, $y);
        return $this;
    }

    public function curveTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): self
    {
        $this->buffer .= Operators::curveTo($c1x, $c1y, $c2x, $c2y, $x, $y);
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
}
