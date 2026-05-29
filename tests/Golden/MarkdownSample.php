<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

/**
 * Deterministic Markdown samples shared by the Markdown golden fixtures.
 *
 * Kept here so the regenerator (tests/Golden/regenerate.php) and the three
 * golden test classes build byte-identical input without drift.
 */
final class MarkdownSample
{
    /**
     * Single sample exercising headings, inline emphasis/code/links, bullet
     * and ordered lists (with nesting), a block quote, a fenced code block,
     * a thematic break, and a closing paragraph.
     */
    public const string TEXT = <<<'MD'
# Markdown Demo

A paragraph with **bold**, *italic*, `code`, and a [link](https://example.test).

- first bullet
- second bullet
    - nested bullet

1. one
2. two

> a block quote
> spanning two lines

```
echo "hi";
return 0;
```

---

Final paragraph.
MD;

    /**
     * Builds a long, fully deterministic Markdown document by looping a
     * stable section template enough times to force several pages.
     */
    public static function multipage(int $sections = 60): string
    {
        $parts = [];
        for ($i = 1; $i <= $sections; $i++) {
            $parts[] = "## Section {$i}\n\nBody text for section {$i}. "
                . 'This sentence is intentionally verbose so each section consumes '
                . 'enough vertical space to push the flowing Markdown across page '
                . "boundaries in a stable, reproducible way.\n";
        }

        return implode("\n", $parts);
    }
}
