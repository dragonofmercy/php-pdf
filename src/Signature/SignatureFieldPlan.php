<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * How one queued signature's field should be realized: reuse an existing empty
 * /Sig field, or create a new visible field on a target page. Absence of a plan
 * for a field name means "create a new invisible field on the first page" (the
 * default). {@see IncrementalRevisionStacker} dispatches on which of the two
 * named constructors built the plan.
 *
 * @internal
 */
final readonly class SignatureFieldPlan
{
    /**
     * @param array{page: IndirectObject, rect: list<float>, appearance: SignatureAppearance}|null $visible
     */
    private function __construct(
        public ?IndirectObject $existingField = null,
        public ?array $visible = null,
    ) {}

    /** Sets /V on an existing empty /FT /Sig field. */
    public static function reuse(IndirectObject $existingField): self
    {
        return new self(existingField: $existingField);
    }

    /**
     * Creates a new visible /FT /Sig field carrying a /Rect and an /AP caption.
     *
     * @param list<float> $rect [llx, lly, urx, ury] in PDF points
     */
    public static function visible(IndirectObject $targetPage, array $rect, SignatureAppearance $appearance): self
    {
        return new self(visible: ['page' => $targetPage, 'rect' => $rect, 'appearance' => $appearance]);
    }
}
