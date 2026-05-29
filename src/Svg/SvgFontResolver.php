<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Font;

/**
 * Maps an SVG font-family list plus weight/style to a Font. Registered custom
 * families win (case-insensitive) over the standard keyword map; an
 * unrecognized family falls back to Helvetica.
 *
 * @internal
 */
final class SvgFontResolver
{
    /**
     * @param array<string, string> $aliases lowercased registered alias => actual alias
     */
    public static function resolve(string $family, bool $bold, bool $italic, array $aliases): Font
    {
        $base = Font::helvetica();
        foreach (explode(',', $family) as $token) {
            $name = trim(trim($token), "\"' \t");
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            if (isset($aliases[$lower])) {
                $base = Font::custom($aliases[$lower]);
                break;
            }
            $resolved = match (true) {
                in_array($lower, ['serif', 'times', 'times new roman', 'georgia', 'garamond'], true) => Font::times(),
                in_array($lower, ['monospace', 'courier', 'courier new', 'consolas', 'menlo'], true) => Font::courier(),
                in_array($lower, ['sans-serif', 'helvetica', 'arial', 'verdana', 'tahoma', 'segoe ui'], true) => Font::helvetica(),
                default => null,
            };
            if ($resolved !== null) {
                $base = $resolved;
                break;
            }
        }
        if ($bold) {
            $base = $base->bold();
        }
        if ($italic) {
            $base = $base->italic();
        }
        return $base;
    }
}
