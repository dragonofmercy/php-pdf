<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Flatten;

use DragonOfMercy\PhpPdf\Form\Fill\ResolvedField;

/**
 * One field to flatten: the resolved field, its final value (the pending
 * setField value if it was filled this session, else the current /V decoded by
 * PdfEditor), and whether its appearance must be regenerated (true when the
 * field was filled this session, so the burned appearance reflects the new
 * value; false to reuse the existing /AP).
 *
 * @internal
 */
final readonly class FlattenTarget
{
    /** @param string|bool|array<mixed>|null $value */
    public function __construct(
        public ResolvedField $field,
        public string|bool|array|null $value,
        public bool $regenerate,
    ) {}
}
