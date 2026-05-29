<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Markdown\Node\BlockNode;
use DragonOfMercy\PhpPdf\Markdown\Node\BlockQuote;
use DragonOfMercy\PhpPdf\Markdown\Node\BulletList;
use DragonOfMercy\PhpPdf\Markdown\Node\CodeBlock;
use DragonOfMercy\PhpPdf\Markdown\Node\Heading;
use DragonOfMercy\PhpPdf\Markdown\Node\ImageSpan;
use DragonOfMercy\PhpPdf\Markdown\Node\InlineNode;
use DragonOfMercy\PhpPdf\Markdown\Node\LinkSpan;
use DragonOfMercy\PhpPdf\Markdown\Node\ListItem;
use DragonOfMercy\PhpPdf\Markdown\Node\OrderedList;
use DragonOfMercy\PhpPdf\Markdown\Node\Paragraph;
use DragonOfMercy\PhpPdf\Markdown\Node\TextRun;
use DragonOfMercy\PhpPdf\Markdown\Node\ThematicBreak;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Page;

/**
 * Walks a list of Markdown block AST nodes and draws them into a box on a
 * {@see Page}, returning the consumed height in the page's document unit.
 *
 * The renderer reuses Page's public drawing primitives (text/rect/line/image/
 * link) for every emission, so its output stays byte-consistent with the rest
 * of the library and is testable through the content stream.
 *
 * Internally everything is computed in POINTS; coordinates are converted to and
 * from the page unit at the boundary via {@see toPt()} / {@see fromPt()}. The
 * vertical cursor (cursorYPt) tracks the TOP of the next block, measured from
 * the page origin (top-down Y, matching Page::text()).
 *
 * Baseline convention: {@see Page::text()} positions text at its baseline. For
 * a laid-out {@see Line} whose top sits at lineTopPt, the baseline is placed at
 * lineTopPt + (line size in points), approximating the ascent with the font
 * size. This keeps glyphs inside the line box; precise ascent tuning is left to
 * the golden-fixture task.
 *
 * In ATOMIC mode the renderer never breaks across pages: it keeps advancing the
 * cursor and returns the full consumed height. In FLOW mode (a non-null
 * $onPageBreak callback) it breaks at LINE granularity: before drawing any line
 * whose bottom would cross the page's bottom limit it invokes the callback,
 * rebinds the active page, and resumes at the returned top Y.
 */
final class BoxRenderer
{
    private const float LINE_HEIGHT_FACTOR = 1.2;

    private const float DEFAULT_THEMATIC_LINE_WIDTH_PT = 0.5;

    /**
     * FLOW page-break controller, set for the duration of a FLOW render() and
     * null otherwise (ATOMIC). When present, drawing methods consult it before
     * emitting each line and may swap the active page mid-render.
     */
    private ?FlowBreaker $flow = null;

    /**
     * @param list<BlockNode> $ast
     * @param bool $measureOnly when true, performs the identical layout and
     *        cursor math but skips every drawing emission (text / rect / line /
     *        image / link), returning the same consumed height. Used by callers
     *        that need to size a box before drawing its background/border.
     * @param ?callable():array{0: Page, 1: float} $onPageBreak when $mode is
     *        FLOW and this is non-null, invoked before a line that would overflow
     *        the page bottom limit; it must create/return the next page and the
     *        top Y (document unit) to continue at. Ignored in ATOMIC mode or when
     *        $measureOnly is true.
     * @return float consumed height in the page's document unit (on the FINAL
     *         page, when FLOW broke across pages)
     */
    public function render(
        array $ast,
        MarkdownStyle $style,
        float $x,
        float $y,
        float $width,
        Page $page,
        BreakMode $mode,
        bool $measureOnly = false,
        ?callable $onPageBreak = null,
    ): float {
        $bodyFont = $page->getFont();
        $bodySizePt = $style->bodySize ?? $page->getFontSize();

        $measure = static fn (string $t, Font $f, float $s): float => $page->measureStringPt($t, $f, $s);
        $breaker = new LineBreaker($measure);

        $topPt = $this->toPt($page, $y);
        $xPt = $this->toPt($page, $x);
        $widthPt = $this->toPt($page, $width);

        // FLOW is only active when a callback is supplied and we are actually
        // drawing; measureOnly keeps the pure ATOMIC layout math.
        $this->flow = ($mode === BreakMode::FLOW && $onPageBreak !== null && !$measureOnly)
            ? new FlowBreaker($page, $topPt, $onPageBreak)
            : null;

        try {
            $cursorYPt = $this->renderBlocks(
                $ast,
                $style,
                $bodyFont,
                $bodySizePt,
                $xPt,
                $topPt,
                $widthPt,
                $page,
                $breaker,
                0,
                $measureOnly,
            );

            // After a FLOW break the consumed height is measured on the final
            // page (cursor minus that page's top), which the caller uses to
            // place the cursor below the last drawn content.
            $finalTopPt = $this->flow?->lastTopPt() ?? $topPt;
        } finally {
            $this->flow = null;
        }

        return $this->fromPt($page, $cursorYPt - $finalTopPt);
    }

    /**
     * Walks a sequence of blocks at a given left edge / width, advancing a top
     * cursor and returning its final value (in points).
     *
     * @param list<BlockNode> $blocks
     */
    private function renderBlocks(
        array $blocks,
        MarkdownStyle $style,
        Font $bodyFont,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        int $depth,
        bool $measureOnly,
    ): float {
        $blockSpacingPt = $this->toPt($page, $style->blockSpacing);
        $first = true;

        foreach ($blocks as $block) {
            if (!$first) {
                $cursorYPt += $blockSpacingPt;
            }
            $first = false;

            $cursorYPt = match (true) {
                $block instanceof Heading => $this->renderHeading($block, $style, $bodyFont, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly),
                $block instanceof Paragraph => $this->renderParagraph($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly),
                $block instanceof CodeBlock => $this->renderCodeBlock($block, $style, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $measureOnly),
                $block instanceof BlockQuote => $this->renderBlockQuote($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly),
                $block instanceof BulletList => $this->renderBulletList($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly),
                $block instanceof OrderedList => $this->renderOrderedList($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly),
                $block instanceof ThematicBreak => $this->renderThematicBreak($style, $xPt, $cursorYPt, $widthPt, $page, $measureOnly),
                default => $cursorYPt,
            };
        }

        return $cursorYPt;
    }

    private function renderHeading(
        Heading $heading,
        MarkdownStyle $style,
        Font $bodyFont,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        bool $measureOnly,
    ): float {
        $sizePt = $style->headingSizes[$heading->level];
        $headingFont = $bodyFont->bold();

        $runs = [];
        foreach ($this->flattenInlines($heading->inlines) as $flat) {
            $runs[] = new StyledRun(
                $flat['text'],
                $headingFont,
                $flat['url'] !== null ? $style->linkColor : $this->bodyColor(),
                $sizePt,
                false,
                $flat['url'],
            );
        }

        $cursorYPt += $this->toPt($page, $style->headingSpacingBefore);
        $cursorYPt = $this->drawRuns($runs, $style, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly);
        $cursorYPt += $this->toPt($page, $style->headingSpacingAfter);

        return $cursorYPt;
    }

    private function renderParagraph(
        Paragraph $paragraph,
        MarkdownStyle $style,
        Font $bodyFont,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        bool $measureOnly,
    ): float {
        // A paragraph made solely of a single image renders the image block-level.
        $imageOnly = $this->soleImage($paragraph->inlines);
        if ($imageOnly !== null) {
            return $this->drawBlockImage($imageOnly, $xPt, $cursorYPt, $widthPt, $page, $measureOnly);
        }

        $runs = $this->inlineRuns($paragraph->inlines, $style, $bodyFont, $bodySizePt);
        $cursorYPt = $this->drawRuns($runs, $style, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly);
        $cursorYPt += $this->toPt($page, $style->paragraphSpacing);

        return $cursorYPt;
    }

    private function renderCodeBlock(
        CodeBlock $code,
        MarkdownStyle $style,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        bool $measureOnly,
    ): float {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $code->text));
        $lineHeightPt = $bodySizePt * self::LINE_HEIGHT_FACTOR;
        $paddingPt = $this->toPt($page, $style->codeBlockPadding);
        $blockHeightPt = count($lines) * $lineHeightPt + 2 * $paddingPt;

        if ($measureOnly) {
            return $cursorYPt + $blockHeightPt;
        }

        if ($this->flow !== null) {
            return $this->renderCodeBlockFlowing($lines, $style, $bodySizePt, $xPt, $cursorYPt, $widthPt, $lineHeightPt, $paddingPt, $page);
        }

        $this->drawCodeSlice($lines, $style, $bodySizePt, $xPt, $cursorYPt, $widthPt, $lineHeightPt, $paddingPt, $page);

        return $cursorYPt + $blockHeightPt;
    }

    /**
     * Draws one self-contained code-block slice (optional background covering
     * top padding + its lines + bottom padding, then the text) on $page. No page
     * breaking: callers either know the block fits or have already sliced it.
     *
     * @param list<string> $lines
     */
    private function drawCodeSlice(
        array $lines,
        MarkdownStyle $style,
        float $bodySizePt,
        float $xPt,
        float $topPt,
        float $widthPt,
        float $lineHeightPt,
        float $paddingPt,
        Page $page,
    ): void {
        if ($lines === []) {
            return;
        }

        $sliceHeightPt = count($lines) * $lineHeightPt + 2 * $paddingPt;
        if ($style->codeBackground !== null) {
            $page->setFillColor($style->codeBackground);
            $page->rect(
                $this->fromPt($page, $xPt),
                $this->fromPt($page, $topPt),
                $this->fromPt($page, $widthPt),
                $this->fromPt($page, $sliceHeightPt),
            )->fill();
        }

        $page->setFillColor($this->bodyColor());
        $textXPt = $xPt + $paddingPt;
        $lineTopPt = $topPt + $paddingPt;
        foreach ($lines as $line) {
            $baselinePt = $lineTopPt + $bodySizePt;
            $page->setFont($style->codeFont, $bodySizePt);
            $page->text(
                $this->fromPt($page, $textXPt),
                $this->fromPt($page, $baselinePt),
                $line,
            );
            $lineTopPt += $lineHeightPt;
        }
    }

    /**
     * FLOW variant of code-block rendering: the block is split at line
     * granularity into per-page slices, each drawn with its own background so it
     * stays self-contained on its page. Returns the cursor below the final
     * slice's bottom padding on the (possibly new) final page.
     *
     * @param list<string> $lines
     */
    private function renderCodeBlockFlowing(
        array $lines,
        MarkdownStyle $style,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        float $lineHeightPt,
        float $paddingPt,
        Page $page,
    ): float {
        $flow = $this->flow;
        if ($flow === null) {
            // Defensive: this method is only reached with an active flow.
            $this->drawCodeSlice($lines, $style, $bodySizePt, $xPt, $cursorYPt, $widthPt, $lineHeightPt, $paddingPt, $page);
            return $cursorYPt + count($lines) * $lineHeightPt + 2 * $paddingPt;
        }

        /** @var list<string> $slice lines accumulated for the current page */
        $slice = [];
        $sliceTopPt = $cursorYPt;
        // The page the current slice is being laid out on. breakIfNeeded() swaps
        // the flow page in place, so we capture the slice's page separately and
        // flush the COMPLETED slice onto it before adopting the new page.
        $slicePage = $flow->page();
        // The first content line sits below the slice's top padding.
        $lineTopPt = $cursorYPt + $paddingPt;

        foreach ($lines as $line) {
            // A line plus the trailing bottom padding must fit; otherwise the
            // slice ends here and the rest continues on a fresh page.
            $newLineTop = $flow->breakIfNeeded($lineTopPt, $lineHeightPt + $paddingPt);
            if ($newLineTop !== $lineTopPt) {
                $this->drawCodeSlice($slice, $style, $bodySizePt, $xPt, $sliceTopPt, $widthPt, $lineHeightPt, $paddingPt, $slicePage);
                $slice = [];
                $slicePage = $flow->page();
                // The new slice's top padding precedes its first line.
                $sliceTopPt = $newLineTop - $paddingPt;
                $lineTopPt = $newLineTop;
            }
            $slice[] = $line;
            $lineTopPt += $lineHeightPt;
        }

        $this->drawCodeSlice($slice, $style, $bodySizePt, $xPt, $sliceTopPt, $widthPt, $lineHeightPt, $paddingPt, $slicePage);

        return $lineTopPt + $paddingPt;
    }

    private function renderBlockQuote(
        BlockQuote $quote,
        MarkdownStyle $style,
        Font $bodyFont,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        int $depth,
        bool $measureOnly,
    ): float {
        $indentPt = $this->toPt($page, $style->blockQuoteIndent);
        $innerXPt = $xPt + $indentPt;
        $innerWidthPt = max(0.0, $widthPt - $indentPt);

        // Remember the page the quote starts on so we can detect whether its
        // content flowed onto a later page (the bar is then drawn per-page).
        $startPage = $this->activePage($page);

        $innerBottomPt = $this->renderBlocks(
            $quote->blocks,
            $style,
            $bodyFont,
            $bodySizePt,
            $innerXPt,
            $cursorYPt,
            $innerWidthPt,
            $page,
            $breaker,
            $depth,
            $measureOnly,
        );

        if ($measureOnly) {
            return $innerBottomPt;
        }

        $endPage = $this->activePage($page);
        $barWidthPt = $this->toPt($page, $style->blockQuoteBarWidth);

        if ($endPage === $startPage) {
            // No break: a single bar spans the whole quote on this page.
            $barHeightPt = $innerBottomPt - $cursorYPt;
            if ($barHeightPt > 0.0) {
                $this->drawQuoteBar($endPage, $style, $xPt, $cursorYPt, $barWidthPt, $barHeightPt);
            }
        } else {
            // The quote flowed across a break: draw the bar segment on the final
            // page only, from that page's content top down to the content bottom.
            // (Intermediate-page bar segments are intentionally omitted to keep
            // the FLOW path simple; the inner text itself is correctly placed.)
            $finalTopPt = $this->flow?->lastTopPt() ?? $cursorYPt;
            $barHeightPt = $innerBottomPt - $finalTopPt;
            if ($barHeightPt > 0.0) {
                $this->drawQuoteBar($endPage, $style, $xPt, $finalTopPt, $barWidthPt, $barHeightPt);
            }
        }

        return $innerBottomPt;
    }

    private function renderBulletList(
        BulletList $list,
        MarkdownStyle $style,
        Font $bodyFont,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        int $depth,
        bool $measureOnly,
    ): float {
        $glyph = $style->bulletGlyphs[$depth % count($style->bulletGlyphs)];

        foreach ($list->items as $item) {
            $cursorYPt = $this->renderListItem($item, $glyph, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly);
            $cursorYPt += $this->toPt($page, $style->listItemSpacing);
        }

        return $cursorYPt;
    }

    private function renderOrderedList(
        OrderedList $list,
        MarkdownStyle $style,
        Font $bodyFont,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        int $depth,
        bool $measureOnly,
    ): float {
        $number = $list->start;
        foreach ($list->items as $item) {
            $cursorYPt = $this->renderListItem($item, $number . '.', $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly);
            $cursorYPt += $this->toPt($page, $style->listItemSpacing);
            $number++;
        }

        return $cursorYPt;
    }

    private function renderListItem(
        ListItem $item,
        string $marker,
        MarkdownStyle $style,
        Font $bodyFont,
        float $bodySizePt,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        int $depth,
        bool $measureOnly,
    ): float {
        $indentPt = $this->toPt($page, $style->listIndent);
        $innerXPt = $xPt + $indentPt;
        $innerWidthPt = max(0.0, $widthPt - $indentPt);

        // The marker sits on the first line baseline of the item content. In
        // FLOW mode, break BEFORE the marker if a single body line would not fit
        // here, so the marker and its first content line stay on the same page.
        if (!$measureOnly && $this->flow !== null) {
            $cursorYPt = $this->flow->breakIfNeeded($cursorYPt, $bodySizePt * self::LINE_HEIGHT_FACTOR);
        }

        if (!$measureOnly) {
            $markerPage = $this->activePage($page);
            $baselinePt = $cursorYPt + $bodySizePt;
            $markerPage->setFillColor($this->bodyColor());
            $markerPage->setFont($bodyFont, $bodySizePt);
            $markerPage->text(
                $this->fromPt($markerPage, $xPt),
                $this->fromPt($markerPage, $baselinePt),
                $marker,
            );
        }

        return $this->renderBlocks(
            $item->blocks,
            $style,
            $bodyFont,
            $bodySizePt,
            $innerXPt,
            $cursorYPt,
            $innerWidthPt,
            $page,
            $breaker,
            $depth + 1,
            $measureOnly,
        );
    }

    private function renderThematicBreak(
        MarkdownStyle $style,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        bool $measureOnly,
    ): float {
        $spacingPt = $this->toPt($page, $style->blockSpacing);

        if ($measureOnly) {
            return $cursorYPt + $spacingPt;
        }

        if ($this->flow !== null) {
            $cursorYPt = $this->flow->breakIfNeeded($cursorYPt, $spacingPt);
            $page = $this->activePage($page);
        }

        $midPt = $cursorYPt + $spacingPt / 2.0;
        $page->setStrokeColor($this->bodyColor());
        $page->setLineWidth($this->fromPt($page, self::DEFAULT_THEMATIC_LINE_WIDTH_PT));
        $page->line(
            $this->fromPt($page, $xPt),
            $this->fromPt($page, $midPt),
            $this->fromPt($page, $xPt + $widthPt),
            $this->fromPt($page, $midPt),
        )->stroke();

        return $cursorYPt + $spacingPt;
    }

    /**
     * Lays out runs into lines over $widthPt and draws each, advancing the
     * cursor. Returns the new top cursor (in points).
     *
     * @param list<StyledRun> $runs
     */
    private function drawRuns(
        array $runs,
        MarkdownStyle $style,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
        LineBreaker $breaker,
        bool $measureOnly,
    ): float {
        $lines = $breaker->layout($runs, $widthPt);
        foreach ($lines as $line) {
            if (!$measureOnly) {
                if ($this->flow !== null) {
                    $cursorYPt = $this->flow->breakIfNeeded($cursorYPt, $line->heightPt);
                }
                $this->drawLine($line, $xPt, $cursorYPt, $this->activePage($page), $style);
            }
            $cursorYPt += $line->heightPt;
        }

        return $cursorYPt;
    }

    /**
     * Draws one laid-out line. Each segment is shown at its measured offset; the
     * baseline sits at lineTopPt + segment size (ascent approximation). Link
     * segments register a clickable area and an optional underline.
     */
    private function drawLine(
        Line $line,
        float $xPt,
        float $lineTopPt,
        Page $page,
        MarkdownStyle $style,
    ): void {
        foreach ($line->segments as $segment) {
            $run = $segment->run;
            $segXPt = $xPt + $segment->xOffsetPt;
            $baselinePt = $lineTopPt + $run->sizePt;

            $page->setFillColor($run->color);
            $page->setFont($run->font, $run->sizePt);
            $page->text(
                $this->fromPt($page, $segXPt),
                $this->fromPt($page, $baselinePt),
                $run->text,
            );

            if ($run->url !== null) {
                $rectTopPt = $lineTopPt;
                $page->link(
                    $this->fromPt($page, $segXPt),
                    $this->fromPt($page, $rectTopPt),
                    $this->fromPt($page, $segment->widthPt),
                    $this->fromPt($page, $line->heightPt),
                    Link::url($run->url),
                );

                if ($style->linkUnderline) {
                    $underlinePt = $baselinePt + $run->sizePt * 0.1;
                    $page->setStrokeColor($run->color);
                    $page->setLineWidth($this->fromPt($page, max($run->sizePt * 0.05, 0.2)));
                    $page->line(
                        $this->fromPt($page, $segXPt),
                        $this->fromPt($page, $underlinePt),
                        $this->fromPt($page, $segXPt + $segment->widthPt),
                        $this->fromPt($page, $underlinePt),
                    )->stroke();
                }
            }
        }
    }

    /**
     * Builds StyledRuns from a paragraph's inline nodes, resolving bold/italic to
     * the body font's variants, inline code to the code font (body color), and
     * links to the link color while carrying their url.
     *
     * @param list<InlineNode> $inlines
     * @return list<StyledRun>
     */
    private function inlineRuns(array $inlines, MarkdownStyle $style, Font $bodyFont, float $bodySizePt): array
    {
        $runs = [];
        foreach ($this->flattenInlines($inlines) as $flat) {
            if ($flat['code']) {
                $font = $style->codeFont;
            } else {
                $font = $bodyFont;
                if ($flat['bold']) {
                    $font = $font->bold();
                }
                if ($flat['italic']) {
                    $font = $font->italic();
                }
            }

            $color = $flat['url'] !== null ? $style->linkColor : $this->bodyColor();

            $runs[] = new StyledRun($flat['text'], $font, $color, $bodySizePt, $flat['code'], $flat['url']);
        }

        return $runs;
    }

    /**
     * Flattens an inline tree (TextRun / LinkSpan / ImageSpan) into a flat list
     * of text fragments carrying their formatting flags and an optional link
     * url. Images inside flowing text are reduced to their alt text (block-level
     * image placement is handled separately by {@see soleImage()}); inline image
     * rendering is deferred.
     *
     * @param list<InlineNode> $inlines
     * @return list<array{text: string, bold: bool, italic: bool, code: bool, url: ?string}>
     */
    private function flattenInlines(array $inlines, ?string $url = null): array
    {
        $out = [];
        foreach ($inlines as $node) {
            if ($node instanceof TextRun) {
                $out[] = [
                    'text' => $node->text,
                    'bold' => $node->bold,
                    'italic' => $node->italic,
                    'code' => $node->code,
                    'url' => $url,
                ];
                continue;
            }
            if ($node instanceof LinkSpan) {
                foreach ($this->flattenInlines($node->children, $node->url) as $child) {
                    $out[] = $child;
                }
                continue;
            }
            if ($node instanceof ImageSpan) {
                if ($node->alt !== '') {
                    $out[] = ['text' => $node->alt, 'bold' => false, 'italic' => false, 'code' => false, 'url' => $url];
                }
                continue;
            }
        }

        return $out;
    }

    /**
     * Returns the lone ImageSpan when $inlines is exactly one image (ignoring
     * empty text), otherwise null.
     *
     * @param list<InlineNode> $inlines
     */
    private function soleImage(array $inlines): ?ImageSpan
    {
        $image = null;
        foreach ($inlines as $node) {
            if ($node instanceof TextRun && trim($node->text) === '') {
                continue;
            }
            if ($node instanceof ImageSpan) {
                if ($image !== null) {
                    return null;
                }
                $image = $node;
                continue;
            }

            return null;
        }

        return $image;
    }

    /**
     * Places an image block-level at the current cursor, clamped to the box
     * width, and returns the cursor advanced past its drawn height.
     */
    private function drawBlockImage(ImageSpan $span, float $xPt, float $cursorYPt, float $widthPt, Page $page, bool $measureOnly): float
    {
        $image = $this->loadImage($span->src);

        $intrinsicWPt = (float) $image->width;
        $intrinsicHPt = (float) $image->height;

        $drawnWPt = $intrinsicWPt;
        $drawnHPt = $intrinsicHPt;
        if ($intrinsicWPt > $widthPt && $intrinsicWPt > 0.0) {
            $drawnWPt = $widthPt;
            $drawnHPt = $widthPt * $intrinsicHPt / $intrinsicWPt;
        }

        if (!$measureOnly) {
            // A block image is atomic: in FLOW mode, if it would overflow the
            // page bottom it moves WHOLLY to the next page (it is never split).
            if ($this->flow !== null) {
                $cursorYPt = $this->flow->breakIfNeeded($cursorYPt, $drawnHPt);
                $page = $this->activePage($page);
            }
            $page->image(
                $image,
                $this->fromPt($page, $xPt),
                $this->fromPt($page, $cursorYPt),
                $this->fromPt($page, $drawnWPt),
                $this->fromPt($page, $drawnHPt),
            );
        }

        return $cursorYPt + $drawnHPt;
    }

    /**
     * Resolves an image source: a `data:` URI is decoded in place, otherwise the
     * src is treated as a filesystem path (Image throws PdfException when the
     * file is unreadable).
     */
    private function loadImage(string $src): Image
    {
        if (str_starts_with($src, 'data:')) {
            return Image::fromBase64($src);
        }

        return Image::fromFile($src);
    }

    private function drawQuoteBar(Page $page, MarkdownStyle $style, float $xPt, float $topPt, float $barWidthPt, float $barHeightPt): void
    {
        $page->setFillColor($style->blockQuoteBarColor);
        $page->rect(
            $this->fromPt($page, $xPt),
            $this->fromPt($page, $topPt),
            $this->fromPt($page, $barWidthPt),
            $this->fromPt($page, $barHeightPt),
        )->fill();
    }

    /**
     * The page to draw on right now: the FLOW controller's current page when a
     * FLOW render is in progress, otherwise the page passed down the call chain.
     */
    private function activePage(Page $page): Page
    {
        return $this->flow?->page() ?? $page;
    }

    private function bodyColor(): Color
    {
        return Color::rgb(0, 0, 0);
    }

    private function toPt(Page $page, float $value): float
    {
        return $page->unit->toPoints($value);
    }

    private function fromPt(Page $page, float $value): float
    {
        return $page->unit->fromPoints($value);
    }
}
