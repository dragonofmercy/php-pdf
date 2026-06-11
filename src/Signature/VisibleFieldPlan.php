<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * Realizes a signature by creating a new visible /FT /Sig field on a target
 * page, with a /Rect and an /AP caption appearance.
 *
 * @internal
 */
final readonly class VisibleFieldPlan implements SignatureFieldPlan
{
    /**
     * @param list<float> $rect [llx, lly, urx, ury] in PDF points
     */
    public function __construct(
        public IndirectObject $targetPage,
        public array $rect,
        public SignatureAppearance $appearance,
    ) {}

    /**
     * @param Closure(int): IndirectObject $valueDictFactory
     * @return array{objects: list<IndirectObject>, size: int, context: RevisionContext}
     */
    public function realize(AppendedFieldRevisionBuilder $builder, RevisionContext $ctx, Closure $valueDictFactory, string $fieldName): array
    {
        return $builder->buildVisible($ctx, $valueDictFactory, $fieldName, $this->targetPage, $this->rect, $this->appearance);
    }
}
