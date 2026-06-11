<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * How the stacker should realize one queued signature's field: reuse an
 * existing empty /Sig field, or create a new visible field on a target page.
 * Absence of a plan for a field name means "create a new invisible field on
 * the first page" (the default).
 *
 * @internal
 */
interface SignatureFieldPlan
{
    /**
     * @param Closure(int): IndirectObject $valueDictFactory
     * @return array{objects: list<IndirectObject>, size: int, context: RevisionContext}
     */
    public function realize(AppendedFieldRevisionBuilder $builder, RevisionContext $ctx, Closure $valueDictFactory, string $fieldName): array;
}
