<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * Realizes a signature by reusing an existing empty /FT /Sig field, setting its
 * /V to the freshly-built value dict.
 *
 * @internal
 */
final readonly class ReuseFieldPlan implements SignatureFieldPlan
{
    public function __construct(public IndirectObject $existingField) {}

    /**
     * @param Closure(int): IndirectObject $valueDictFactory
     * @return array{objects: list<IndirectObject>, size: int, context: RevisionContext}
     */
    public function realize(AppendedFieldRevisionBuilder $builder, RevisionContext $ctx, Closure $valueDictFactory, string $fieldName): array
    {
        return $builder->buildReuse($ctx, $valueDictFactory, $this->existingField);
    }
}
