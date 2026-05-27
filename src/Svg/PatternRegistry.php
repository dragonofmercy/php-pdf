<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Dedups full pattern dictionary strings into P0, P1, ... names for the Form
 * XObject /Resources/Pattern. Mirrors ExtGStateRegistry.
 *
 * @internal
 */
final class PatternRegistry
{
    /** @var array<string, string> dict => name */
    private array $byDict = [];

    /** @var array<string, string> name => dict */
    private array $entries = [];

    private int $next = 0;

    public function nameFor(string $dict): string
    {
        if (isset($this->byDict[$dict])) {
            return $this->byDict[$dict];
        }
        $name = 'P' . $this->next++;
        $this->byDict[$dict] = $name;
        $this->entries[$name] = $dict;
        return $name;
    }

    /** @return array<string, string> */
    public function entries(): array
    {
        return $this->entries;
    }
}
