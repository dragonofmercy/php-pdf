<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Hierarchical outline tree node. Mutable on purpose: declaring the tree
 * incrementally with `$node->add('Title', $dest)` is the natural pattern.
 * After `Document::output()` has run the tree should no longer be mutated;
 * this is a usage convention rather than an enforced invariant.
 *
 * The root node carries no title and no destination (it maps to the PDF
 * `/Outlines` object). All other nodes carry a non-empty title and a
 * `Destination`. Build a tree by chaining `add()`:
 *
 * ```php
 * $root = $document->outline();
 * $chap1 = $root->add('Chapter 1', Destination::page(0));
 * $chap1->add('Section 1.1', Destination::page(1));
 * $root->add('Chapter 2', Destination::page(2));
 * ```
 */
final class OutlineNode
{
    /** @var list<OutlineNode> */
    private array $children = [];

    private function __construct(
        private readonly ?string $title,
        private readonly ?Destination $destination,
        private readonly ?OutlineNode $parent,
    ) {}

    /** Creates the root node. The only public entry point - subsequent nodes come back from `add()`. */
    public static function root(): self
    {
        return new self(title: null, destination: null, parent: null);
    }

    /**
     * Adds a child node with the given title (after trimming, must be
     * non-empty) and destination. Returns the new child so the caller can
     * either descend (`$child->add(...)`) or chain siblings off the parent
     * captured beforehand.
     */
    public function add(string $title, Destination $destination): self
    {
        if (trim($title) === '') {
            throw new PdfException('Outline node title cannot be empty');
        }
        $child = new self(title: $title, destination: $destination, parent: $this);
        $this->children[] = $child;
        return $child;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function destination(): ?Destination
    {
        return $this->destination;
    }

    public function parent(): ?OutlineNode
    {
        return $this->parent;
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /** @return list<OutlineNode> */
    public function children(): array
    {
        return $this->children;
    }
}
