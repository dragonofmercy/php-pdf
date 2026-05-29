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
 * Only ATOMIC mode is implemented here: the renderer never breaks across pages,
 * it keeps advancing the cursor and returns the full consumed height.
 */
final class BoxRenderer
{
    private const float LINE_HEIGHT_FACTOR = 1.2;

    private const float DEFAULT_THEMATIC_LINE_WIDTH_PT = 0.5;

    /**
     * @param list<BlockNode> $ast
     * @return float consumed height in the page's document unit
     */
    public function render(
        array $ast,
        MarkdownStyle $style,
        float $x,
        float $y,
        float $width,
        Page $page,
        BreakMode $mode,
    ): float {
        $bodyFont = $page->getFont();
        $bodySizePt = $style->bodySize ?? $page->getFontSize();

        $measure = static fn (string $t, Font $f, float $s): float => $page->measureStringPt($t, $f, $s);
        $breaker = new LineBreaker($measure);

        $topPt = $this->toPt($page, $y);
        $xPt = $this->toPt($page, $x);
        $widthPt = $this->toPt($page, $width);

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
        );

        return $this->fromPt($page, $cursorYPt - $topPt);
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
    ): float {
        $blockSpacingPt = $this->toPt($page, $style->blockSpacing);
        $first = true;

        foreach ($blocks as $block) {
            if (!$first) {
                $cursorYPt += $blockSpacingPt;
            }
            $first = false;

            $cursorYPt = match (true) {
                $block instanceof Heading => $this->renderHeading($block, $style, $bodyFont, $xPt, $cursorYPt, $widthPt, $page, $breaker),
                $block instanceof Paragraph => $this->renderParagraph($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker),
                $block instanceof CodeBlock => $this->renderCodeBlock($block, $style, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page),
                $block instanceof BlockQuote => $this->renderBlockQuote($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth),
                $block instanceof BulletList => $this->renderBulletList($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth),
                $block instanceof OrderedList => $this->renderOrderedList($block, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth),
                $block instanceof ThematicBreak => $this->renderThematicBreak($style, $xPt, $cursorYPt, $widthPt, $page),
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
        $cursorYPt = $this->drawRuns($runs, $style, $xPt, $cursorYPt, $widthPt, $page, $breaker);
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
    ): float {
        // A paragraph made solely of a single image renders the image block-level.
        $imageOnly = $this->soleImage($paragraph->inlines);
        if ($imageOnly !== null) {
            return $this->drawBlockImage($imageOnly, $xPt, $cursorYPt, $widthPt, $page);
        }

        $runs = $this->inlineRuns($paragraph->inlines, $style, $bodyFont, $bodySizePt);
        $cursorYPt = $this->drawRuns($runs, $style, $xPt, $cursorYPt, $widthPt, $page, $breaker);
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
    ): float {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $code->text));
        $lineHeightPt = $bodySizePt * self::LINE_HEIGHT_FACTOR;
        $paddingPt = $this->toPt($page, $style->codeBlockPadding);
        $blockHeightPt = count($lines) * $lineHeightPt + 2 * $paddingPt;

        if ($style->codeBackground !== null) {
            $page->setFillColor($style->codeBackground);
            $page->rect(
                $this->fromPt($page, $xPt),
                $this->fromPt($page, $cursorYPt),
                $this->fromPt($page, $widthPt),
                $this->fromPt($page, $blockHeightPt),
            )->fill();
        }

        $page->setFillColor($this->bodyColor());
        $textXPt = $xPt + $paddingPt;
        $lineTopPt = $cursorYPt + $paddingPt;
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

        return $cursorYPt + $blockHeightPt;
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
    ): float {
        $indentPt = $this->toPt($page, $style->blockQuoteIndent);
        $innerXPt = $xPt + $indentPt;
        $innerWidthPt = max(0.0, $widthPt - $indentPt);

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
        );

        $barHeightPt = $innerBottomPt - $cursorYPt;
        if ($barHeightPt > 0.0) {
            $barWidthPt = $this->toPt($page, $style->blockQuoteBarWidth);
            $page->setFillColor($style->blockQuoteBarColor);
            $page->rect(
                $this->fromPt($page, $xPt),
                $this->fromPt($page, $cursorYPt),
                $this->fromPt($page, $barWidthPt),
                $this->fromPt($page, $barHeightPt),
            )->fill();
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
    ): float {
        $glyph = $style->bulletGlyphs[$depth % count($style->bulletGlyphs)];

        foreach ($list->items as $item) {
            $cursorYPt = $this->renderListItem($item, $glyph, $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth);
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
    ): float {
        $number = $list->start;
        foreach ($list->items as $item) {
            $cursorYPt = $this->renderListItem($item, $number . '.', $style, $bodyFont, $bodySizePt, $xPt, $cursorYPt, $widthPt, $page, $breaker, $depth);
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
    ): float {
        $indentPt = $this->toPt($page, $style->listIndent);
        $innerXPt = $xPt + $indentPt;
        $innerWidthPt = max(0.0, $widthPt - $indentPt);

        // The marker sits on the first line baseline of the item content.
        $baselinePt = $cursorYPt + $bodySizePt;
        $page->setFillColor($this->bodyColor());
        $page->setFont($bodyFont, $bodySizePt);
        $page->text(
            $this->fromPt($page, $xPt),
            $this->fromPt($page, $baselinePt),
            $marker,
        );

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
        );
    }

    private function renderThematicBreak(
        MarkdownStyle $style,
        float $xPt,
        float $cursorYPt,
        float $widthPt,
        Page $page,
    ): float {
        $spacingPt = $this->toPt($page, $style->blockSpacing);
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
    ): float {
        $lines = $breaker->layout($runs, $widthPt);
        foreach ($lines as $line) {
            $this->drawLine($line, $xPt, $cursorYPt, $page, $style);
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
    private function drawBlockImage(ImageSpan $span, float $xPt, float $cursorYPt, float $widthPt, Page $page): float
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

        $page->image(
            $image,
            $this->fromPt($page, $xPt),
            $this->fromPt($page, $cursorYPt),
            $this->fromPt($page, $drawnWPt),
            $this->fromPt($page, $drawnHPt),
        );

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
