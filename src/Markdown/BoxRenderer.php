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
use DragonOfMercy\PhpPdf\Tagging\ObjrRef;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\StructureType;

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
 *
 * @internal
 */
final class BoxRenderer
{
    private const float DEFAULT_THEMATIC_LINE_WIDTH_PT = 0.5;

    /**
     * Gap between a list marker and its item text, as a fraction of the body
     * font size (roughly one space). The marker is right-aligned to end this
     * far before the content indent, so `-` and `1.` keep a consistent, tight
     * spacing rather than a wide fixed gap.
     */
    private const float LIST_MARKER_GAP_FACTOR = 0.4;

    /**
     * FLOW page-break controller, set for the duration of a FLOW render() and
     * null otherwise (ATOMIC). When present, drawing methods consult it before
     * emitting each line and may swap the active page mid-render.
     */
    private ?FlowBreaker $flow = null;

    /**
     * Active logical-structure tree for the duration of a drawing render(), or
     * null when tagging is off / measuring. When present, each block boundary
     * opens the mapped structure element and brackets the block's text-show
     * operators in a marked-content sequence on the emitting page.
     */
    private ?StructureTree $tree = null;

    /** True while rendering a text block that contains an inline link (fragmented marked content). */
    private bool $fragmentedBlock = false;

    /** The structure type of the block being fragmented, used as the BDC tag for its non-link runs. */
    private ?StructureType $fragmentedBlockType = null;

    /** The link group of the currently-open <Link> element, or null when none is open. */
    private ?int $openLinkGroup = null;

    /** The currently-open <Link> structure element, kept across lines so a wrapped link stays one element. */
    private ?StructElem $openLinkElem = null;

    /**
     * @param list<BlockNode> $ast
     * @param bool $measureOnly when true, performs the identical layout and
     *        cursor math but skips every drawing emission (text / rect / line /
     *        image / link), returning the same consumed height. Used by callers
     *        that need to size a box before drawing its background/border.
     * @param ?callable():array{0: Page, 1: float, 2: float} $onPageBreak when $mode is
     *        FLOW and this is non-null, invoked before a line that would overflow
     *        the page bottom limit; it must create/return the next page, the top
     *        Y (document unit) to continue at, and the x-shift in points for
     *        horizontal emissions on the new page/column. Ignored in ATOMIC mode
     *        or when $measureOnly is true.
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

        // Tag blocks only when actually drawing into a tagged document; the
        // measure pass and the off-path (no structure tree) stay byte-identical.
        // Inside a page artifact scope (header/footer/decorative), suppress
        // tagging so the content is emitted purely as artifact.
        $this->tree = ($measureOnly || $page->isArtifactScope())
            ? null
            : $page->document()?->structureTree();

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
            $this->tree = null;
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
                $block instanceof Heading => $this->tagTextBlock(StructureType::headingForLevel($block->level), $page, $block->inlines, fn (): float => $this->renderHeading($block, $style, $bodyFont, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly)),
                $block instanceof Paragraph => $this->renderParagraphBlock($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly),
                $block instanceof CodeBlock => $this->tagLeaf(StructureType::P, $page, fn (): float => $this->renderCodeBlock($block, $style, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $measureOnly)),
                $block instanceof BlockQuote => $this->renderBlockQuote($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly),
                $block instanceof BulletList => $this->renderBulletList($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly),
                $block instanceof OrderedList => $this->renderOrderedList($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly),
                $block instanceof ThematicBreak => $this->renderThematicBreak($style, $xPt, $cursorYPt, $widthPt, $page, $measureOnly),
                default => $cursorYPt,
            };
        }

        return $cursorYPt;
    }

    /**
     * Tags one leaf-text block (heading / paragraph / code block): opens the
     * mapped structure element, brackets the block's text-show operators in a
     * single marked-content sequence on the emitting page, records the leaf, and
     * closes - all balanced via try/finally even when the block draws nothing.
     *
     * When tagging is off ($this->tree === null) the block is rendered verbatim
     * so the off-path stays byte-identical. The BDC/EMC pair is written to the
     * SAME content stream (the page active when the block begins), so a block
     * that flows across a page break still emits a balanced pair on its starting
     * page; the marked-content id is minted from that page.
     *
     * @param callable(): float $render
     */
    private function tagLeaf(StructureType $type, Page $page, callable $render): float
    {
        $tree = $this->tree;
        if ($tree === null) {
            return $render();
        }

        $emittingPage = $this->activePage($page);
        $stream = $emittingPage->contentStream();
        $mcid = $emittingPage->nextMcid();

        $tree->open($type);
        try {
            $stream->beginMarkedContent($type->value, $mcid);
            try {
                return $render();
            } finally {
                $stream->endMarkedContent();
            }
        } finally {
            $tree->addMarkedContent($emittingPage->pageIndex(), $mcid);
            $tree->close();
        }
    }

    /**
     * Routes a text block (paragraph / heading) to the monolithic single-MCID
     * {@see tagLeaf} path when it has no inline link, or to the fragmented path
     * when it does, so inline links become nested <Link> elements. Off-path
     * (no tree) and the no-link case stay byte-identical to the prior behaviour.
     *
     * @param list<InlineNode>  $inlines
     * @param callable(): float $render
     */
    private function tagTextBlock(StructureType $type, Page $page, array $inlines, callable $render): float
    {
        if ($this->tree !== null && $this->inlinesContainLink($inlines)) {
            return $this->tagFragmentedBlock($type, $render);
        }

        return $this->tagLeaf($type, $page, $render);
    }

    /** @param list<InlineNode> $inlines */
    private function inlinesContainLink(array $inlines): bool
    {
        foreach ($inlines as $node) {
            if ($node instanceof LinkSpan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tags a block whose inlines contain a link. Unlike {@see tagLeaf}, no single
     * enclosing marked-content sequence is emitted: {@see drawLineFragmented}
     * emits one marked-content run per maximal same-link stretch, attributing
     * non-link runs to this block element and link runs to nested <Link>
     * elements. The block element is opened here and closed in the finally,
     * after any dangling <Link>.
     *
     * @param callable(): float $render
     */
    private function tagFragmentedBlock(StructureType $type, callable $render): float
    {
        $tree = $this->tree;
        if ($tree === null) {
            return $render();
        }

        $tree->open($type);
        $prevFlag = $this->fragmentedBlock;
        $prevType = $this->fragmentedBlockType;
        $this->fragmentedBlock = true;
        $this->fragmentedBlockType = $type;
        try {
            return $render();
        } finally {
            $this->closeOpenLink();
            $this->fragmentedBlock = $prevFlag;
            $this->fragmentedBlockType = $prevType;
            $tree->close();
        }
    }

    /** Closes the currently-open <Link> element (if any) and clears the link state. */
    private function closeOpenLink(): void
    {
        if ($this->openLinkElem !== null) {
            $this->tree?->close();
            $this->openLinkElem = null;
            $this->openLinkGroup = null;
        }
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
                $flat['group'],
            );
        }

        $cursorYPt += $this->toPt($page, $style->headingSpacingBefore);
        $cursorYPt = $this->drawRuns($runs, $style, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly);
        $cursorYPt += $this->toPt($page, $style->headingSpacingAfter);

        return $cursorYPt;
    }

    /**
     * Renders a paragraph block, tagging it as <P> for a normal text paragraph.
     * A paragraph that is solely a block-level image is NOT wrapped in P: the
     * image is drawn through {@see Page::image()}, which tags itself as <Figure>,
     * so the structure tree gets a Figure leaf rather than an empty P.
     */
    private function renderParagraphBlock(
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
        if ($this->soleImage($paragraph->inlines) !== null) {
            return $this->renderParagraph($paragraph, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly);
        }

        return $this->tagTextBlock(StructureType::P, $page, $paragraph->inlines, fn (): float => $this->renderParagraph($paragraph, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $measureOnly));
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
        $lineHeightPt = $bodySizePt * LineBreaker::LINE_HEIGHT_FACTOR;
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
                $this->emitX($page, $xPt),
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
                $this->emitX($page, $textXPt),
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

        return $this->tagList(fn (): float => $this->renderListItems($list->items, static fn (int $i): string => $glyph, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly));
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
        $start = $list->start;

        return $this->tagList(fn (): float => $this->renderListItems($list->items, static fn (int $i): string => ($start + $i) . '.', $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly));
    }

    /**
     * Shared list-item loop: renders each item with the marker produced by
     * $markerFor (called with the zero-based item index) and adds inter-item
     * spacing. Bullet lists pass a constant glyph; ordered lists number from
     * their start.
     *
     * @param list<ListItem> $items
     * @param callable(int): string $markerFor
     */
    private function renderListItems(
        array $items,
        callable $markerFor,
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
        $index = 0;
        foreach ($items as $item) {
            $cursorYPt = $this->renderListItem($item, $markerFor($index), $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth, $measureOnly);
            $cursorYPt += $this->toPt($page, $style->listItemSpacing);
            $index++;
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
            $cursorYPt = $this->flow->breakIfNeeded($cursorYPt, $bodySizePt * LineBreaker::LINE_HEIGHT_FACTOR);
        }

        if (!$measureOnly) {
            $markerPage = $this->activePage($page);
            $baselinePt = $cursorYPt + $bodySizePt;
            // Right-align the marker to end LIST_MARKER_GAP_FACTOR * size before
            // the content indent, clamped to the item's left edge, so the gap
            // between marker and text is tight and consistent across markers.
            $markerWidthPt = $markerPage->measureStringPt($marker, $bodyFont, $bodySizePt);
            $markerGapPt = $bodySizePt * self::LIST_MARKER_GAP_FACTOR;
            $markerXPt = max($xPt, $innerXPt - $markerGapPt - $markerWidthPt);
            $markerPage->withArtifactScope(function () use ($markerPage, $markerXPt, $baselinePt, $marker, $bodyFont, $bodySizePt): void {
                $markerPage->setFillColor($this->bodyColor());
                $markerPage->setFont($bodyFont, $bodySizePt);
                $markerPage->text(
                    $this->emitX($markerPage, $markerXPt),
                    $this->fromPt($markerPage, $baselinePt),
                    $marker,
                );
            });
        }

        return $this->tagListItem(fn (): float => $this->renderBlocks(
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
        ));
    }

    /**
     * Opens an L element around a whole list's items and closes it afterwards,
     * balanced via try/finally. Off-path (no tree) renders verbatim.
     *
     * @param callable(): float $render
     */
    private function tagList(callable $render): float
    {
        $tree = $this->tree;
        if ($tree === null) {
            return $render();
        }
        $tree->open(StructureType::L);
        try {
            return $render();
        } finally {
            $tree->close();
        }
    }

    /**
     * Opens LI then LBody around one list item's block content (the inner
     * paragraphs tag themselves as P leaves inside the LBody), then closes both,
     * balanced via try/finally. Off-path (no tree) renders verbatim.
     *
     * @param callable(): float $render
     */
    private function tagListItem(callable $render): float
    {
        $tree = $this->tree;
        if ($tree === null) {
            return $render();
        }
        $tree->open(StructureType::LI);
        try {
            $tree->open(StructureType::LBody);
            try {
                return $render();
            } finally {
                $tree->close();
            }
        } finally {
            $tree->close();
        }
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
            $this->emitX($page, $xPt),
            $this->fromPt($page, $midPt),
            $this->emitX($page, $xPt + $widthPt),
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
        $linkTexts = $this->collectLinkTexts($runs);
        $lines = $breaker->layout($runs, $widthPt);
        foreach ($lines as $line) {
            if (!$measureOnly) {
                if ($this->flow !== null) {
                    $cursorYPt = $this->flow->breakIfNeeded($cursorYPt, $line->heightPt);
                }
                $this->drawLine($line, $xPt, $cursorYPt, $this->activePage($page), $style, $linkTexts);
            }
            $cursorYPt += $line->heightPt;
        }

        return $cursorYPt;
    }

    /**
     * Concatenates each link group's full visible text, so a wrapped link's
     * per-line annotations all carry the complete anchor text in /Contents.
     *
     * @param list<StyledRun> $runs
     * @return array<int, string>
     */
    private function collectLinkTexts(array $runs): array
    {
        $texts = [];
        foreach ($runs as $run) {
            if ($run->linkGroup !== null) {
                $texts[$run->linkGroup] = ($texts[$run->linkGroup] ?? '') . $run->text;
            }
        }

        return $texts;
    }

    /**
     * Dispatches to the fragmented tagging path for a link-bearing block, or to
     * the legacy path (untagged area link + underline) otherwise. The legacy
     * path is reached for measure passes, the off-path, and link-free blocks,
     * keeping their output byte-identical.
     *
     * @param array<int, string> $linkTexts
     */
    private function drawLine(Line $line, float $xPt, float $lineTopPt, Page $page, MarkdownStyle $style, array $linkTexts): void
    {
        if ($this->tree !== null && $this->fragmentedBlock) {
            $this->drawLineFragmented($line, $xPt, $lineTopPt, $page, $style, $linkTexts);

            return;
        }

        $this->drawLineLegacy($line, $xPt, $lineTopPt, $page, $style);
    }

    /**
     * Draws one line in the fragmented tagging path. Segments are grouped into
     * maximal stretches sharing a link group (or none). A non-link stretch emits
     * one marked-content run attributed to the block element; a link stretch
     * opens (or continues across lines) a <Link> element, emits its
     * marked-content run, registers a tagged annotation over the merged
     * rectangle, appends an OBJR, and draws the optional underline.
     *
     * @param array<int, string> $linkTexts
     */
    private function drawLineFragmented(Line $line, float $xPt, float $lineTopPt, Page $page, MarkdownStyle $style, array $linkTexts): void
    {
        $tree = $this->tree;
        if ($tree === null) {
            return;
        }
        $blockTag = ($this->fragmentedBlockType ?? StructureType::P)->value;

        $segments = $line->segments;
        $count = count($segments);
        $i = 0;
        while ($i < $count) {
            $group = $segments[$i]->run->linkGroup;
            $j = $i;
            while ($j < $count && $segments[$j]->run->linkGroup === $group) {
                $j++;
            }
            $stretch = array_slice($segments, $i, $j - $i);
            $i = $j;

            if ($group !== $this->openLinkGroup) {
                $this->closeOpenLink();
                if ($group !== null) {
                    $this->openLinkElem = $tree->open(StructureType::Link);
                    $this->openLinkGroup = $group;
                }
            }

            $mcid = $page->nextMcid();
            $page->contentStream()->beginMarkedContent($group === null ? $blockTag : StructureType::Link->value, $mcid);
            foreach ($stretch as $segment) {
                $run = $segment->run;
                $segXPt = $xPt + $segment->xOffsetPt;
                $baselinePt = $lineTopPt + $run->sizePt;
                $page->setFillColor($run->color);
                $page->setFont($run->font, $run->sizePt);
                $page->text($this->emitX($page, $segXPt), $this->fromPt($page, $baselinePt), $run->text);
            }
            $page->contentStream()->endMarkedContent();
            $tree->addMarkedContent($page->pageIndex(), $mcid);

            if ($group !== null) {
                $firstXPt = $xPt + $stretch[0]->xOffsetPt;
                $widthPt = 0.0;
                foreach ($stretch as $segment) {
                    $widthPt += $segment->widthPt;
                }
                $url = $stretch[0]->run->url ?? '';
                $annot = $page->registerTaggedMarkdownLink(
                    $this->emitX($page, $firstXPt),
                    $this->fromPt($page, $lineTopPt),
                    $this->fromPt($page, $widthPt),
                    $this->fromPt($page, $line->heightPt),
                    Link::url($url),
                    $linkTexts[$group] ?? '',
                );
                $this->openLinkElem?->appendObjr(new ObjrRef($annot, $page->pageIndex()));

                if ($style->linkUnderline) {
                    $run = $stretch[0]->run;
                    $underlinePt = $lineTopPt + $run->sizePt + $run->sizePt * 0.1;
                    // The underline is a visual decoration of the link, not
                    // semantic content: bracket it as an /Artifact so PDF/UA-1
                    // does not flag it as untagged real content.
                    $page->withArtifactScope(function () use ($page, $run, $firstXPt, $widthPt, $underlinePt): void {
                        $page->setStrokeColor($run->color);
                        $page->setLineWidth($this->fromPt($page, max($run->sizePt * 0.05, 0.2)));
                        $page->line(
                            $this->emitX($page, $firstXPt),
                            $this->fromPt($page, $underlinePt),
                            $this->emitX($page, $firstXPt + $widthPt),
                            $this->fromPt($page, $underlinePt),
                        )->stroke();
                    });
                }
            }
        }
    }

    /**
     * Draws one laid-out line. Each segment is shown at its measured offset; the
     * baseline sits at lineTopPt + segment size (ascent approximation). Link
     * segments register a clickable area and an optional underline.
     */
    private function drawLineLegacy(
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
                $this->emitX($page, $segXPt),
                $this->fromPt($page, $baselinePt),
                $run->text,
            );

            if ($run->url !== null) {
                $rectTopPt = $lineTopPt;
                $page->link(
                    $this->emitX($page, $segXPt),
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
                        $this->emitX($page, $segXPt),
                        $this->fromPt($page, $underlinePt),
                        $this->emitX($page, $segXPt + $segment->widthPt),
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

            $runs[] = new StyledRun($flat['text'], $font, $color, $bodySizePt, $flat['code'], $flat['url'], $flat['group']);
        }

        return $runs;
    }

    /**
     * Flattens an inline tree (TextRun / LinkSpan / ImageSpan) into a flat list
     * of text fragments carrying their formatting flags, an optional link url,
     * and a per-occurrence link group id (null outside any link; a distinct
     * integer per LinkSpan, so two links sharing a URL stay separate). Images
     * inside flowing text are reduced to their alt text (block-level image
     * placement is handled separately by {@see soleImage()}); inline image
     * rendering is deferred.
     *
     * @param list<InlineNode> $inlines
     * @return list<array{text: string, bold: bool, italic: bool, code: bool, url: ?string, group: ?int}>
     */
    private function flattenInlines(array $inlines): array
    {
        $counter = 0;

        return $this->flattenInlinesInner($inlines, null, null, $counter);
    }

    /**
     * Recursive worker for {@see flattenInlines}: carries the enclosing link url
     * and group down into LinkSpan children, minting a fresh group id (from the
     * by-ref counter, local to one block) for each LinkSpan occurrence.
     *
     * @param list<InlineNode> $inlines
     * @return list<array{text: string, bold: bool, italic: bool, code: bool, url: ?string, group: ?int}>
     */
    private function flattenInlinesInner(array $inlines, ?string $url, ?int $group, int &$counter): array
    {
        $out = [];
        foreach ($inlines as $node) {
            if ($node instanceof TextRun) {
                $out[] = ['text' => $node->text, 'bold' => $node->bold, 'italic' => $node->italic, 'code' => $node->code, 'url' => $url, 'group' => $group];
                continue;
            }
            if ($node instanceof LinkSpan) {
                $linkGroup = $counter++;
                foreach ($this->flattenInlinesInner($node->children, $node->url, $linkGroup, $counter) as $child) {
                    $out[] = $child;
                }
                continue;
            }
            if ($node instanceof ImageSpan) {
                if ($node->alt !== '') {
                    $out[] = ['text' => $node->alt, 'bold' => false, 'italic' => false, 'code' => false, 'url' => $url, 'group' => $group];
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
                $this->emitX($page, $xPt),
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
            $this->emitX($page, $xPt),
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

    /**
     * Points-to-unit conversion for y, width, and height. For a HORIZONTAL x
     * position (a left edge, x1, or x2) use {@see emitX} instead, so the active
     * flow's column x-shift is applied - otherwise content will not move into
     * the correct column.
     */
    private function fromPt(Page $page, float $value): float
    {
        return $page->unit->fromPoints($value);
    }

    /** Horizontal coordinate conversion that adds the active flow's column x-shift. */
    private function emitX(Page $page, float $xPt): float
    {
        return $this->fromPt($page, $xPt + ($this->flow?->xShiftPt() ?? 0.0));
    }
}
