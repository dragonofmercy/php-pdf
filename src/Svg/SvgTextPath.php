<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * A <textPath> flattened to styled runs laid along the referenced path's
 * geometry. The path commands are captured in the same user space as the
 * surrounding <text>. startOffset is a user-unit distance, or a fraction of the
 * total path length when $startOffsetIsPercent is true.
 *
 * @internal
 */
final readonly class SvgTextPath implements SvgNode
{
    /**
     * @param list<SvgPathCommand> $pathCommands
     * @param list<SvgTextSpan> $spans
     */
    public function __construct(
        public ?SvgMatrix $transform,
        public array $pathCommands,
        public array $spans,
        public float $startOffset,
        public bool $startOffsetIsPercent,
    ) {}
}
