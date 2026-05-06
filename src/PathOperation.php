<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Page\Operators;

/**
 * Terminal-only chainable returned by Page primitives (line, rect, circle).
 * Appends the paint operator to the shared ContentStream.
 */
final readonly class PathOperation
{
    public function __construct(private ContentStream $stream) {}

    public function stroke(): void
    {
        $this->stream->append(Operators::stroke());
    }

    public function fill(): void
    {
        $this->stream->append(Operators::fill());
    }

    public function strokeAndFill(): void
    {
        $this->stream->append(Operators::fillStroke());
    }
}
