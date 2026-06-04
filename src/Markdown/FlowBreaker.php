<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Page;

/**
 * Drives line-granularity page breaks for {@see BoxRenderer} in FLOW mode.
 *
 * It tracks the page currently being drawn on (which changes as content flows
 * past the bottom limit) and the top Y (in points) of that page's content area.
 * Before a line is emitted, {@see breakIfNeeded()} decides whether the line's
 * bottom would cross the active page's bottom limit; if so it invokes the
 * caller-supplied callback to obtain the next page and continuation top Y, then
 * rebinds itself to that page.
 *
 * @internal
 */
final class FlowBreaker
{
    /** Absorbs float drift on an exact-fit line, mirroring Page::OVERFLOW_EPSILON_PT. */
    private const float OVERFLOW_EPSILON_PT = 0.0001;

    private Page $page;

    /** Top Y (points) of the active page's content area, used to report consumed height. */
    private float $topPt;

    /** Bottom limit (points) of the active page: pageHeight minus its bottom margin. */
    private float $bottomLimitPt;

    /** @var callable():array{0: Page, 1: float, 2: float} */
    private $onPageBreak;

    /** X-shift in points applied to horizontal emissions after the most recent break. */
    private float $xShiftPt = 0.0;

    /**
     * @param float $topPt top Y (points) the content starts at on $page; used as
     *        the no-break baseline for {@see lastTopPt()} and the guard that
     *        prevents an infinite break loop on an over-tall first line.
     * @param callable():array{0: Page, 1: float, 2: float} $onPageBreak
     */
    public function __construct(Page $page, float $topPt, callable $onPageBreak)
    {
        $this->page = $page;
        $this->onPageBreak = $onPageBreak;
        $this->topPt = $topPt;
        $this->bottomLimitPt = $this->bottomLimitOf($page);
    }

    /**
     * The page currently being drawn on. Drawing methods must emit onto this
     * page (not the one originally passed to render) so that content lands on
     * the right page after a break.
     */
    public function page(): Page
    {
        return $this->page;
    }

    /** Top Y (points) of the most recent page started, used to compute consumed height. */
    public function lastTopPt(): float
    {
        return $this->topPt;
    }

    /**
     * X-shift in points to add to every horizontal emission after the most
     * recent column/page break. Zero before the first break and when no column
     * layout is active (regular page-break flow).
     */
    public function xShiftPt(): float
    {
        return $this->xShiftPt;
    }

    /**
     * Decides whether a line of height $lineHeightPt whose top sits at
     * $cursorYPt would overflow the active page's bottom limit. When it would,
     * triggers the callback (new page + continuation top), rebinds the active
     * page, and returns the new top cursor; otherwise returns $cursorYPt
     * unchanged. Never breaks when the line starts exactly at the page top
     * (a single line taller than the drawable area cannot be split, so it is
     * drawn where it is to avoid an infinite break loop).
     */
    public function breakIfNeeded(float $cursorYPt, float $lineHeightPt): float
    {
        $bottomPt = $cursorYPt + $lineHeightPt;
        if ($bottomPt <= $this->bottomLimitPt + self::OVERFLOW_EPSILON_PT) {
            return $cursorYPt;
        }
        // The line is already at the very top of the content area: breaking
        // would loop forever, so draw it here even though it overflows.
        if ($cursorYPt <= $this->topPt + self::OVERFLOW_EPSILON_PT) {
            return $cursorYPt;
        }

        [$newPage, $topY, $xShiftPt] = ($this->onPageBreak)();
        $this->page = $newPage;
        $this->topPt = $newPage->unit->toPoints($topY);
        $this->xShiftPt = $xShiftPt;
        $this->bottomLimitPt = $this->bottomLimitOf($newPage);

        return $this->topPt;
    }

    private function bottomLimitOf(Page $page): float
    {
        return $page->pageHeight - $page->unit->toPoints($page->margins()->bottom);
    }
}
