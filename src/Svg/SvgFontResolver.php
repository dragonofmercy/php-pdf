<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Font;

/**
 * Maps an SVG font-family list plus weight/style to one of the 14 standard
 * PDF fonts. Custom registered TTF families are not honored here (out of
 * scope for this delivery); any unrecognized family falls back to Helvetica.
 *
 * @internal
 */
final class SvgFontResolver
{
    public static function resolve(string $family, bool $bold, bool $italic): Font
    {
        $base = Font::helvetica();
        foreach (explode(',', $family) as $token) {
            $name = strtolower(trim(trim($token), "\"' \t"));
            if ($name === '') {
                continue;
            }
            $resolved = match (true) {
                in_array($name, ['serif', 'times', 'times new roman', 'georgia', 'garamond'], true) => Font::times(),
                in_array($name, ['monospace', 'courier', 'courier new', 'consolas', 'menlo'], true) => Font::courier(),
                in_array($name, ['sans-serif', 'helvetica', 'arial', 'verdana', 'tahoma', 'segoe ui'], true) => Font::helvetica(),
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
