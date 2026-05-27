<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Css;

/**
 * One compound CSS selector: an optional type (null = universal `*`), a set of
 * classes (all required to match), and an optional id. Combinators, pseudo
 * classes/elements, and attribute selectors are out of scope and never produce
 * a CssSelector (the parser skips them).
 *
 * @internal
 */
final readonly class CssSelector
{
    /**
     * @param list<string> $classes
     */
    public function __construct(
        public ?string $type,
        public array $classes,
        public ?string $id,
    ) {}

    /**
     * @param list<string> $classes the element's class list
     */
    public function matches(string $tag, array $classes, ?string $id): bool
    {
        if ($this->type !== null && $this->type !== $tag) {
            return false;
        }
        if ($this->id !== null && $this->id !== $id) {
            return false;
        }
        foreach ($this->classes as $class) {
            if (!in_array($class, $classes, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * CSS specificity as (id count, class count, type count). Compared
     * lexicographically; PHP array comparison already does this.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function specificity(): array
    {
        return [
            $this->id !== null ? 1 : 0,
            count($this->classes),
            $this->type !== null ? 1 : 0,
        ];
    }
}
