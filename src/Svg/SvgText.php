<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * A <text> element flattened to a list of positioned runs. Carries an optional
 * local transform (emitted as a q/cm/Q wrapper). Implements SvgNode directly
 * (text manages its own paint per span, so it is not an SvgShape).
 *
 * @internal
 */
final readonly class SvgText implements SvgNode
{
    /**
     * @param list<SvgTextSpan> $spans
     */
    public function __construct(
        public ?SvgMatrix $transform,
        public array $spans,
    ) {}
}
