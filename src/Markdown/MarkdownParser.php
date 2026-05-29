<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Markdown\Node\BlockNode;
use DragonOfMercy\PhpPdf\Markdown\Node\BlockQuote;
use DragonOfMercy\PhpPdf\Markdown\Node\BulletList;
use DragonOfMercy\PhpPdf\Markdown\Node\CodeBlock;
use DragonOfMercy\PhpPdf\Markdown\Node\Heading;
use DragonOfMercy\PhpPdf\Markdown\Node\ListItem;
use DragonOfMercy\PhpPdf\Markdown\Node\OrderedList;
use DragonOfMercy\PhpPdf\Markdown\Node\Paragraph;
use DragonOfMercy\PhpPdf\Markdown\Node\ThematicBreak;

/**
 * Block-level scanner that turns a Markdown document into a list of block AST
 * nodes, delegating every run of inline text to {@see InlineParser::parse()}.
 *
 * The scan is line-based: input is normalised (CRLF/CR to LF, hard tabs to 4
 * spaces) and split into lines, then a single index walks the lines and
 * dispatches each one by its leading pattern. The indentation nesting unit is
 * 4 spaces; a continuation/nested line must be indented to at least the list
 * item's content indent to belong to it.
 *
 * This is a deliberately pragmatic subset of CommonMark, scoped to the
 * constructs the library renders: ATX headings, paragraphs (with soft-wrap
 * line joining), bullet/ordered lists with nesting, fenced and indented code,
 * block quotes and thematic breaks. Full CommonMark edge cases (setext
 * headings, lazy continuation, link reference definitions, HTML blocks, list
 * marker tightness across loose nesting, etc.) are intentionally out of scope.
 */
final class MarkdownParser
{
    /** Indentation nesting unit, in spaces. */
    private const int INDENT_UNIT = 4;

    /**
     * @return list<BlockNode>
     */
    public static function parse(string $markdown): array
    {
        $lines = self::splitLines($markdown);

        return self::parseLines($lines);
    }

    /**
     * Normalises newlines, expands hard tabs to 4 spaces, and splits into lines.
     *
     * @return list<string>
     */
    private static function splitLines(string $markdown): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $normalized = str_replace("\t", str_repeat(' ', self::INDENT_UNIT), $normalized);

        return explode("\n", $normalized);
    }

    /**
     * Walks the given lines and dispatches each block construct.
     *
     * @param list<string> $lines
     * @return list<BlockNode>
     */
    private static function parseLines(array $lines): array
    {
        /** @var list<BlockNode> $blocks */
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while($i < $count) {
            $line = $lines[$i];

            if(self::isBlank($line)) {
                $i++;
                continue;
            }

            if(self::fenceMarker($line) !== null) {
                [$node, $i] = self::consumeFencedCode($lines, $i);
                $blocks[] = $node;
                continue;
            }

            $heading = self::matchHeading($line);
            if($heading !== null) {
                $blocks[] = $heading;
                $i++;
                continue;
            }

            if(self::isThematicBreak($line)) {
                $blocks[] = new ThematicBreak();
                $i++;
                continue;
            }

            if(self::isBlockQuote($line)) {
                [$node, $i] = self::consumeBlockQuote($lines, $i);
                $blocks[] = $node;
                continue;
            }

            if(self::matchListMarker($line) !== null) {
                [$node, $i] = self::consumeList($lines, $i);
                $blocks[] = $node;
                continue;
            }

            if(self::isIndentedCode($line)) {
                [$node, $i] = self::consumeIndentedCode($lines, $i);
                $blocks[] = $node;
                continue;
            }

            [$node, $i] = self::consumeParagraph($lines, $i);
            $blocks[] = $node;
        }

        return $blocks;
    }

    private static function isBlank(string $line): bool
    {
        return trim($line) === '';
    }

    /**
     * Returns the leading indent width (spaces) of a line.
     */
    private static function indentWidth(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    // -- ATX headings --------------------------------------------------------

    private static function matchHeading(string $line): ?Heading
    {
        if(preg_match('/^ {0,3}(#{1,6})(?:\s+(.*?))?\s*$/', $line, $m) !== 1) {
            return null;
        }

        $level = strlen($m[1]);
        $text = $m[2] ?? '';
        // Strip an optional closing run of hashes (ATX closing sequence).
        $text = preg_replace('/\s*#+\s*$/', '', $text) ?? $text;

        return new Heading($level, InlineParser::parse(trim($text)));
    }

    // -- Thematic break ------------------------------------------------------

    private static function isThematicBreak(string $line): bool
    {
        return preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $line) === 1;
    }

    // -- Fenced code ---------------------------------------------------------

    /**
     * Returns the fence descriptor (char + length) if the line opens a fence.
     *
     * @return array{char: string, length: int, info: string}|null
     */
    private static function fenceMarker(string $line): ?array
    {
        if(preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $m) !== 1) {
            return null;
        }

        return ['char' => $m[1][0], 'length' => strlen($m[1]), 'info' => trim($m[2])];
    }

    /**
     * Consumes a fenced code block, collecting raw lines until a matching
     * closing fence of the same character and at least the opening length, or
     * EOF. The info string after the opening fence becomes the language hint
     * (null when empty); neither the body nor the lang is inline-parsed.
     *
     * @param list<string> $lines
     * @return array{0: CodeBlock, 1: int}
     */
    private static function consumeFencedCode(array $lines, int $start): array
    {
        $open = self::fenceMarker($lines[$start]);
        // The caller only dispatches here when fenceMarker matched.
        assert($open !== null);

        $lang = $open['info'] === '' ? null : $open['info'];
        $count = count($lines);
        $i = $start + 1;
        $body = '';

        while($i < $count) {
            $close = self::fenceMarker($lines[$i]);
            if($close !== null && $close['char'] === $open['char'] && $close['length'] >= $open['length'] && $close['info'] === '') {
                $i++;
                break;
            }
            $body .= $lines[$i] . "\n";
            $i++;
        }

        return [new CodeBlock($body, $lang), $i];
    }

    // -- Indented code -------------------------------------------------------

    private static function isIndentedCode(string $line): bool
    {
        return preg_match('/^ {4,}\S/', $line) === 1;
    }

    /**
     * Consumes a run of 4-space-indented lines (blank lines included while a
     * further indented line follows) into a single code block.
     *
     * @param list<string> $lines
     * @return array{0: CodeBlock, 1: int}
     */
    private static function consumeIndentedCode(array $lines, int $start): array
    {
        $count = count($lines);
        $i = $start;
        $body = '';

        while($i < $count) {
            $line = $lines[$i];
            if(self::isBlank($line)) {
                // Include a blank line only if a further indented line follows.
                $j = $i + 1;
                while($j < $count && self::isBlank($lines[$j])) {
                    $j++;
                }
                if($j >= $count || !self::isIndentedCode($lines[$j])) {
                    break;
                }
                $body .= "\n";
                $i++;
                continue;
            }
            if(!self::isIndentedCode($line)) {
                break;
            }
            $body .= substr($line, self::INDENT_UNIT) . "\n";
            $i++;
        }

        return [new CodeBlock($body, null), $i];
    }

    // -- Block quote ---------------------------------------------------------

    private static function isBlockQuote(string $line): bool
    {
        return preg_match('/^ {0,3}>/', $line) === 1;
    }

    /**
     * Consumes contiguous block-quote lines, strips one leading `>` and an
     * optional following space from each, then recursively parses the joined
     * body.
     *
     * @param list<string> $lines
     * @return array{0: BlockQuote, 1: int}
     */
    private static function consumeBlockQuote(array $lines, int $start): array
    {
        $count = count($lines);
        $i = $start;
        /** @var list<string> $inner */
        $inner = [];

        while($i < $count && self::isBlockQuote($lines[$i])) {
            $stripped = preg_replace('/^ {0,3}> ?/', '', $lines[$i]) ?? '';
            $inner[] = $stripped;
            $i++;
        }

        return [new BlockQuote(self::parseLines($inner)), $i];
    }

    // -- Lists ---------------------------------------------------------------

    /**
     * Matches a list marker at the start of a line.
     *
     * @return array{indent: int, ordered: bool, number: int, markerChar: string, contentIndent: int}|null
     */
    private static function matchListMarker(string $line): ?array
    {
        if(preg_match('/^( *)([-*+])(\s+)/', $line, $m) === 1) {
            $indent = strlen($m[1]);
            $markerWidth = 1 + strlen($m[3]);

            return [
                'indent' => $indent,
                'ordered' => false,
                'number' => 0,
                'markerChar' => $m[2],
                'contentIndent' => $indent + $markerWidth,
            ];
        }

        if(preg_match('/^( *)(\d{1,9})([.)])(\s+)/', $line, $m) === 1) {
            $indent = strlen($m[1]);
            $markerWidth = strlen($m[2]) + 1 + strlen($m[4]);

            return [
                'indent' => $indent,
                'ordered' => true,
                'number' => (int) $m[2],
                'markerChar' => $m[3],
                'contentIndent' => $indent + $markerWidth,
            ];
        }

        return null;
    }

    /**
     * Consumes a whole list whose items share the same marker family
     * (bullet vs ordered) at the same indent as the first item. Each item
     * gathers its first-line content plus subsequent lines that are blank or
     * indented to at least the item's content indent; those continuation lines
     * are dedented and recursively parsed, which yields nested lists and
     * multi-block items naturally. The list is tight unless a blank line
     * separates two items.
     *
     * @param list<string> $lines
     * @return array{0: BulletList|OrderedList, 1: int}
     */
    private static function consumeList(array $lines, int $start): array
    {
        $first = self::matchListMarker($lines[$start]);
        assert($first !== null);

        $ordered = $first['ordered'];
        $indent = $first['indent'];
        $listStart = $first['number'];
        $count = count($lines);
        $i = $start;

        /** @var list<ListItem> $items */
        $items = [];
        $tight = true;
        $sawBlankBetween = false;

        while($i < $count) {
            $marker = self::matchListMarker($lines[$i]);
            if($marker === null || $marker['indent'] !== $indent || $marker['ordered'] !== $ordered) {
                break;
            }

            // A blank line that preceded this (non-first) item makes the list loose.
            if($items !== [] && $sawBlankBetween) {
                $tight = false;
            }

            $contentIndent = $marker['contentIndent'];
            /** @var list<string> $itemLines */
            $itemLines = [self::dedent($lines[$i], $contentIndent, true)];
            $i++;

            $sawBlankBetween = false;
            while($i < $count) {
                $line = $lines[$i];
                if(self::isBlank($line)) {
                    // Look ahead: a blank belongs to the item only if a further
                    // indented line follows; otherwise the item (and run) ends.
                    $j = $i + 1;
                    while($j < $count && self::isBlank($lines[$j])) {
                        $j++;
                    }
                    if($j < $count && self::indentWidth($lines[$j]) >= $contentIndent) {
                        $itemLines[] = '';
                        $i++;
                        continue;
                    }
                    $sawBlankBetween = true;
                    $i++;
                    break;
                }
                if(self::indentWidth($line) < $contentIndent) {
                    break;
                }
                $itemLines[] = self::dedent($line, $contentIndent, false);
                $i++;
            }

            $items[] = new ListItem(self::parseLines($itemLines));
        }

        if($ordered) {
            return [new OrderedList($listStart, $items, $tight), $i];
        }

        return [new BulletList($items, $tight), $i];
    }

    /**
     * Removes up to $width leading spaces. When $afterMarker is true the line
     * starts with the list marker, so the marker prefix (everything up to the
     * content indent) is dropped instead.
     */
    private static function dedent(string $line, int $width, bool $afterMarker): string
    {
        if($afterMarker) {
            return substr($line, $width);
        }

        $remove = min($width, self::indentWidth($line));

        return substr($line, $remove);
    }

    // -- Paragraph -----------------------------------------------------------

    /**
     * Collects consecutive non-blank lines that do not start another block
     * construct, joins them with a single space (soft-wrap), and inline-parses
     * the result.
     *
     * @param list<string> $lines
     * @return array{0: Paragraph, 1: int}
     */
    private static function consumeParagraph(array $lines, int $start): array
    {
        $count = count($lines);
        $i = $start;
        /** @var list<string> $collected */
        $collected = [];

        while($i < $count) {
            $line = $lines[$i];
            if(self::isBlank($line)) {
                break;
            }
            if($i !== $start && self::startsNewBlock($line)) {
                break;
            }
            $collected[] = trim($line);
            $i++;
        }

        $text = implode(' ', $collected);

        return [new Paragraph(InlineParser::parse($text)), $i];
    }

    /**
     * Whether a line begins a block construct that interrupts a paragraph.
     */
    private static function startsNewBlock(string $line): bool
    {
        return self::fenceMarker($line) !== null
            || self::matchHeading($line) !== null
            || self::isThematicBreak($line)
            || self::isBlockQuote($line)
            || self::matchListMarker($line) !== null;
    }
}
