<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Css;

/**
 * Parses the concatenated text of one or more <style> elements into a
 * CssStylesheet. Supports flat rule sets with simple + compound selectors
 * (type, class, id, universal, and their combinations) grouped by commas.
 *
 * Out of scope and skipped silently: combinators, pseudo classes/elements,
 * attribute selectors, at-rules (@media/@import/@font-face...), and !important
 * priority (the token is stripped, the declaration kept as normal).
 *
 * @internal
 */
final class CssParser
{
    public static function parse(string $css): CssStylesheet
    {
        $css = self::stripComments($css);
        // Drop block-less at-statements such as @import url(...); or @charset "x";
        $css = preg_replace('/@[a-zA-Z-]+[^{;]*;/', '', $css) ?? $css;

        $entries = [];
        $order = 0;
        $offset = 0;
        $length = strlen($css);

        while ($offset < $length) {
            $brace = strpos($css, '{', $offset);
            if ($brace === false) {
                break;
            }
            $selectorPart = trim(substr($css, $offset, $brace - $offset));

            if ($selectorPart !== '' && $selectorPart[0] === '@') {
                // At-rule with a block (e.g. @media): skip the whole balanced block.
                $offset = self::skipBlock($css, $brace);
                continue;
            }

            $close = strpos($css, '}', $brace);
            if ($close === false) {
                break;
            }
            $body = substr($css, $brace + 1, $close - $brace - 1);
            $offset = $close + 1;

            if ($selectorPart === '') {
                continue;
            }
            $declarations = self::parseDeclarations($body);
            if ($declarations === []) {
                continue;
            }
            foreach (explode(',', $selectorPart) as $rawSelector) {
                $selector = self::parseSelector($rawSelector);
                if ($selector === null) {
                    continue;
                }
                $entries[] = [
                    'selector' => $selector,
                    'declarations' => $declarations,
                    'order' => $order++,
                ];
            }
        }

        return new CssStylesheet($entries);
    }

    private static function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }

    /**
     * Returns the offset just past the balanced block that opens at $openBrace.
     */
    private static function skipBlock(string $css, int $openBrace): int
    {
        $depth = 0;
        $length = strlen($css);
        for ($i = $openBrace; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }
        return $length;
    }

    /**
     * @return array<string, string>
     */
    private static function parseDeclarations(string $body): array
    {
        $out = [];
        foreach (explode(';', $body) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            $value = trim(substr($declaration, $colon + 1));
            $value = trim(preg_replace('/!\s*important$/i', '', $value) ?? $value);
            if ($property !== '' && $value !== '') {
                $out[$property] = $value;
            }
        }
        return $out;
    }

    /**
     * Parses one simple or compound selector. Returns null for an empty or
     * unsupported selector (combinator, pseudo, attribute selector).
     */
    private static function parseSelector(string $selector): ?CssSelector
    {
        $selector = trim($selector);
        if ($selector === '') {
            return null;
        }
        // Reject any construct beyond simple/compound selectors.
        if (preg_match('/[\s>+~:\[\]()]/', $selector) === 1) {
            return null;
        }
        if (preg_match('/^(\*|[a-zA-Z][\w-]*)?((?:[.#][\w-]+)*)$/', $selector, $m) !== 1) {
            return null;
        }
        $typeToken = $m[1];
        $fragments = $m[2];
        if ($typeToken === '' && $fragments === '') {
            return null;
        }

        $type = ($typeToken === '' || $typeToken === '*') ? null : $typeToken;
        $classes = [];
        $id = null;
        if ($fragments !== '') {
            preg_match_all('/([.#])([\w-]+)/', $fragments, $parts, PREG_SET_ORDER);
            foreach ($parts as $part) {
                if ($part[1] === '.') {
                    $classes[] = $part[2];
                } else {
                    $id = $part[2];
                }
            }
        }
        return new CssSelector($type, $classes, $id);
    }
}
