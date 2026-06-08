<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Markdown\Node\ImageSpan;
use DragonOfMercy\PhpPdf\Markdown\Node\InlineNode;
use DragonOfMercy\PhpPdf\Markdown\Node\LinkSpan;
use DragonOfMercy\PhpPdf\Markdown\Node\TextRun;

/**
 * Parses a single logical line of Markdown text into inline AST nodes.
 *
 * The scan is a single left-to-right pass over the raw bytes. Markdown's
 * inline markers are all ASCII, so byte indexing is safe on UTF-8 input
 * (multibyte sequences never collide with the ASCII range). Full CommonMark
 * left/right-flanking emphasis rules are out of scope; a simple matched-pair
 * interpretation is used instead.
 *
 * @internal
 */
final class InlineParser
{
    private const string PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * @return list<InlineNode>
     */
    public static function parse(string $text): array
    {
        return self::parseInner($text, false, false);
    }

    /**
     * @return list<InlineNode>
     */
    private static function parseInner(string $text, bool $bold, bool $italic): array
    {
        /** @var list<InlineNode> $nodes */
        $nodes = [];
        $buffer = '';
        $length = strlen($text);
        $i = 0;

        while($i < $length) {
            $char = $text[$i];

            if($char === '\\' && $i + 1 < $length && self::isPunctuation($text[$i + 1])) {
                $buffer .= $text[$i + 1];
                $i += 2;
                continue;
            }

            if($char === '`') {
                $close = strpos($text, '`', $i + 1);
                if($close === false) {
                    $buffer .= $char;
                    $i++;
                    continue;
                }
                self::flush($nodes, $buffer, $bold, $italic);
                $content = substr($text, $i + 1, $close - $i - 1);
                $nodes[] = new TextRun($content, false, false, true);
                $i = $close + 1;
                continue;
            }

            if($char === '!' && $i + 1 < $length && $text[$i + 1] === '[') {
                $image = self::tryParseImage($text, $i);
                if($image !== null) {
                    self::flush($nodes, $buffer, $bold, $italic);
                    $nodes[] = $image['node'];
                    $i = $image['next'];
                    continue;
                }
                $buffer .= $char;
                $i++;
                continue;
            }

            if($char === '[') {
                $link = self::tryParseLink($text, $i, $bold, $italic);
                if($link !== null) {
                    self::flush($nodes, $buffer, $bold, $italic);
                    $nodes[] = $link['node'];
                    $i = $link['next'];
                    continue;
                }
                $buffer .= $char;
                $i++;
                continue;
            }

            if($char === '*' || $char === '_') {
                $emphasis = self::tryParseEmphasis($text, $i, $char, $bold, $italic);
                if($emphasis !== null) {
                    self::flush($nodes, $buffer, $bold, $italic);
                    foreach($emphasis['nodes'] as $node) {
                        $nodes[] = $node;
                    }
                    $i = $emphasis['next'];
                    continue;
                }
            }

            $buffer .= $char;
            $i++;
        }

        self::flush($nodes, $buffer, $bold, $italic);

        return self::mergeAdjacent($nodes);
    }

    /**
     * Flushes the literal buffer into a TextRun and clears it.
     *
     * @param list<InlineNode> $nodes
     */
    private static function flush(array &$nodes, string &$buffer, bool $bold, bool $italic): void
    {
        if($buffer !== '') {
            $nodes[] = new TextRun($buffer, $bold, $italic, false);
            $buffer = '';
        }
    }

    /**
     * @return array{node: ImageSpan, next: int}|null
     */
    private static function tryParseImage(string $text, int $start): ?array
    {
        $span = self::matchBracketParen($text, $start + 1);
        if($span === null) {
            return null;
        }

        return ['node' => new ImageSpan($span['label'], $span['target']), 'next' => $span['next']];
    }

    /**
     * @return array{node: LinkSpan, next: int}|null
     */
    private static function tryParseLink(string $text, int $start, bool $bold, bool $italic): ?array
    {
        $span = self::matchBracketParen($text, $start);
        if($span === null) {
            return null;
        }

        $children = self::parseInner($span['label'], $bold, $italic);

        return ['node' => new LinkSpan($children, $span['target']), 'next' => $span['next']];
    }

    /**
     * Matches the `[label](target)` shape starting at the `[` byte.
     *
     * @return array{label: string, target: string, next: int}|null
     */
    private static function matchBracketParen(string $text, int $bracket): ?array
    {
        $length = strlen($text);
        $labelEnd = self::findClosingBracket($text, $bracket + 1);
        if($labelEnd === null) {
            return null;
        }

        $parenOpen = $labelEnd + 1;
        if($parenOpen >= $length || $text[$parenOpen] !== '(') {
            return null;
        }

        $parenClose = strpos($text, ')', $parenOpen + 1);
        if($parenClose === false) {
            return null;
        }

        $label = substr($text, $bracket + 1, $labelEnd - $bracket - 1);
        $target = substr($text, $parenOpen + 1, $parenClose - $parenOpen - 1);

        return ['label' => $label, 'target' => $target, 'next' => $parenClose + 1];
    }

    /**
     * Finds the matching unescaped `]` for a label opened at $from, balancing
     * nested `[...]` pairs so a link wrapping an image (`[![alt](img)](url)`)
     * closes on its own bracket rather than the inner image's.
     */
    private static function findClosingBracket(string $text, int $from): ?int
    {
        $length = strlen($text);
        $depth = 0;
        for($i = $from; $i < $length; $i++) {
            if($text[$i] === '\\' && $i + 1 < $length) {
                $i++;
                continue;
            }
            if($text[$i] === '[') {
                $depth++;
                continue;
            }
            if($text[$i] === ']') {
                if($depth === 0) {
                    return $i;
                }
                $depth--;
            }
        }

        return null;
    }

    /**
     * Attempts to match an emphasis span opened with the delimiter run at $start.
     *
     * @return array{nodes: list<InlineNode>, next: int}|null
     */
    private static function tryParseEmphasis(string $text, int $start, string $marker, bool $bold, bool $italic): ?array
    {
        $runLength = self::delimiterRunLength($text, $start, $marker);
        $delimiter = str_repeat($marker, $runLength);

        $contentStart = $start + $runLength;
        $closer = self::findCloser($text, $contentStart, $delimiter);
        if($closer === null) {
            return null;
        }

        $inner = substr($text, $contentStart, $closer - $contentStart);
        if($inner === '') {
            return null;
        }

        [$nextBold, $nextItalic] = self::toggle($runLength, $bold, $italic);
        $nodes = self::parseInner($inner, $nextBold, $nextItalic);

        return ['nodes' => $nodes, 'next' => $closer + $runLength];
    }

    /**
     * Counts how many of the same marker char (capped at 3) start at $start.
     */
    private static function delimiterRunLength(string $text, int $start, string $marker): int
    {
        $length = strlen($text);
        $run = 0;
        while($start + $run < $length && $run < 3 && $text[$start + $run] === $marker) {
            $run++;
        }

        return $run;
    }

    /**
     * Finds the matching closing delimiter run for an opener.
     */
    private static function findCloser(string $text, int $from, string $delimiter): ?int
    {
        $length = strlen($text);
        $delimLength = strlen($delimiter);
        $marker = $delimiter[0];

        for($i = $from; $i + $delimLength <= $length; $i++) {
            if($text[$i] === '\\' && $i + 1 < $length) {
                $i++;
                continue;
            }
            if(substr($text, $i, $delimLength) !== $delimiter) {
                continue;
            }
            // Reject a longer run so the requested width matches exactly.
            $before = $i > $from ? $text[$i - 1] : '';
            $after = $i + $delimLength < $length ? $text[$i + $delimLength] : '';
            if($before === $marker || $after === $marker) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Applies the delimiter width to the inherited emphasis state.
     *
     * @return array{0: bool, 1: bool}
     */
    private static function toggle(int $runLength, bool $bold, bool $italic): array
    {
        if($runLength >= 3) {
            return [!$bold, !$italic];
        }
        if($runLength === 2) {
            return [!$bold, $italic];
        }

        return [$bold, !$italic];
    }

    private static function isPunctuation(string $char): bool
    {
        return strpos(self::PUNCTUATION, $char) !== false;
    }

    /**
     * Merges neighbouring TextRuns sharing identical flags into single runs.
     *
     * @param list<InlineNode> $nodes
     * @return list<InlineNode>
     */
    private static function mergeAdjacent(array $nodes): array
    {
        /** @var list<InlineNode> $merged */
        $merged = [];
        foreach($nodes as $node) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];
            if($node instanceof TextRun && $last instanceof TextRun
                && $last->bold === $node->bold && $last->italic === $node->italic && $last->code === $node->code) {
                $merged[count($merged) - 1] = new TextRun($last->text . $node->text, $last->bold, $last->italic, $last->code);
                continue;
            }
            $merged[] = $node;
        }

        return $merged;
    }
}
