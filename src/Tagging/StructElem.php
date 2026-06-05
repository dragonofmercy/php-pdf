<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

/**
 * One node of the logical structure tree, mutated while content is drawn.
 * Children are nested StructElems and/or MarkedContentRef leaves, in document
 * order. Object numbers are assigned later by StructTreeEmitter.
 *
 * @internal
 */
final class StructElem
{
    /** @var list<StructElem|MarkedContentRef> */
    private array $children = [];

    private ?string $alt = null;

    private ?TableScope $scope = null;

    public function __construct(
        private readonly StructureType $type,
    ) {}

    public function type(): StructureType
    {
        return $this->type;
    }

    public function alt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(string $alt): void
    {
        $this->alt = $alt;
    }

    public function scope(): ?TableScope
    {
        return $this->scope;
    }

    public function setScope(TableScope $scope): void
    {
        $this->scope = $scope;
    }

    public function appendChild(StructElem $child): void
    {
        $this->children[] = $child;
    }

    public function appendMarkedContent(MarkedContentRef $ref): void
    {
        $this->children[] = $ref;
    }

    /** @return list<StructElem|MarkedContentRef> */
    public function children(): array
    {
        return $this->children;
    }
}
