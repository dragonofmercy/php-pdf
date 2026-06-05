<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Build-time accumulator for the logical structure tree. Drawing code opens an
 * element, attaches marked-content leaves, then closes it. The tree is rooted
 * at a single Document element and serialized later by StructTreeEmitter.
 *
 * @internal
 */
final class StructureTree
{
    private readonly StructElem $root;

    /** @var list<StructElem> insertion stack, deepest last */
    private array $stack;

    public function __construct()
    {
        $this->root = new StructElem(StructureType::Document);
        $this->stack = [$this->root];
    }

    public function root(): StructElem
    {
        return $this->root;
    }

    public function open(StructureType $type): StructElem
    {
        $elem = new StructElem($type);
        $this->stack[count($this->stack) - 1]->appendChild($elem);
        $this->stack[] = $elem;
        return $elem;
    }

    public function close(): void
    {
        if (count($this->stack) <= 1) {
            throw new PdfException('StructureTree::close() called with no open element');
        }
        array_pop($this->stack);
    }

    public function addMarkedContent(int $pageIndex, int $mcid): void
    {
        $this->stack[count($this->stack) - 1]->appendMarkedContent(new MarkedContentRef($pageIndex, $mcid));
    }

    public function withElement(StructureType $type, callable $body): void
    {
        $this->open($type);
        try {
            $body();
        } finally {
            $this->close();
        }
    }
}
