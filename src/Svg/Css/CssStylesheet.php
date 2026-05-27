<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Css;

/**
 * A parsed author stylesheet: an ordered list of (selector, declarations,
 * source order) entries. `declarationsFor` collapses the cascade for one
 * element - matching entries sorted by specificity then source order, with
 * later/more-specific declarations overlaid last.
 *
 * @internal
 * @phpstan-type Entry array{selector: CssSelector, declarations: array<string, string>, order: int}
 */
final readonly class CssStylesheet
{
    /**
     * @param list<Entry> $entries
     */
    public function __construct(private array $entries) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $classes the element's class list
     * @return array<string, string> property => value, cascade-collapsed
     */
    public function declarationsFor(string $tag, array $classes, ?string $id): array
    {
        $matched = [];
        foreach ($this->entries as $entry) {
            if ($entry['selector']->matches($tag, $classes, $id)) {
                $matched[] = $entry;
            }
        }
        if ($matched === []) {
            return [];
        }

        usort($matched, static function (array $a, array $b): int {
            $cmp = $a['selector']->specificity() <=> $b['selector']->specificity();
            return $cmp !== 0 ? $cmp : $a['order'] <=> $b['order'];
        });

        $out = [];
        foreach ($matched as $entry) {
            foreach ($entry['declarations'] as $property => $value) {
                $out[$property] = $value;
            }
        }
        return $out;
    }
}
