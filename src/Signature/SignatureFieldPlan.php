<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * How the stacker should realize one queued signature's field: reuse an
 * existing empty /Sig field, or create a new visible field on a target page.
 * Absence of a plan for a field name means "create a new invisible field on
 * the first page" (the default).
 *
 * @internal
 */
final readonly class SignatureFieldPlan
{
    /**
     * @param 'reuse'|'visible' $mode
     * @param list<float>|null $rect [llx, lly, urx, ury] in PDF points (visible)
     */
    private function __construct(
        public string $mode,
        public ?IndirectObject $existingField = null,
        public ?IndirectObject $targetPage = null,
        public ?array $rect = null,
        public ?SignatureAppearance $appearance = null,
    ) {}

    public static function reuse(IndirectObject $existingField): self
    {
        return new self('reuse', existingField: $existingField);
    }

    /**
     * @param list<float> $rect
     */
    public static function visible(IndirectObject $targetPage, array $rect, SignatureAppearance $appearance): self
    {
        return new self('visible', targetPage: $targetPage, rect: $rect, appearance: $appearance);
    }
}
