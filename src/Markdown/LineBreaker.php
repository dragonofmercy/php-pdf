<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Font;

/**
 * Greedy line breaker that lays a sequence of StyledRuns into lines fitting a
 * given width, supporting MIXED fonts on a single line.
 *
 * Width measurement is injected as a callable (string, Font, float): float so
 * the breaker is PURE and testable without font files; the production caller
 * passes a closure backed by the page font engine.
 *
 * Hard line breaks are NOT handled here: the caller must split its runs at
 * hard breaks (one layout() call per logical line/paragraph fragment) before
 * invoking layout(). Tokenisation splits on the ASCII space (0x20) only.
 *
 * @internal
 */
final class LineBreaker
{
    private const float EPSILON = 1e-9;

    private const float LINE_HEIGHT_FACTOR = 1.2;

    /** @var callable(string, Font, float): float */
    private $measure;

    /**
     * @param callable(string, Font, float): float $measure
     */
    public function __construct(callable $measure)
    {
        $this->measure = $measure;
    }

    /**
     * @param list<StyledRun> $runs
     * @return list<Line>
     */
    public function layout(array $runs, float $widthPt): array
    {
        $tokens = $this->tokenise($runs);

        if ($tokens === []) {
            return [];
        }

        $lines = [];

        /** @var list<array{run: StyledRun, text: string, width: float, isSpace: bool}> $current */
        $current = [];
        $currentWidth = 0.0;

        foreach ($tokens as $token) {
            // Drop a leading space token at the start of a line.
            if ($current === [] && $token['isSpace']) {
                continue;
            }

            $fits = $currentWidth + $token['width'] <= $widthPt + self::EPSILON;

            if (!$fits && $current !== []) {
                $lines[] = $this->buildLine($current);
                $current = [];
                $currentWidth = 0.0;

                // A leading space on the freshly opened line is dropped.
                if ($token['isSpace']) {
                    continue;
                }
            }

            // Either it fits, or the line is empty (place an oversized word alone).
            $current[] = $token;
            $currentWidth += $token['width'];
        }

        if ($current !== []) {
            $lines[] = $this->buildLine($current);
        }

        return $lines;
    }

    /**
     * Split each run into alternating word and single-space tokens, preserving
     * the source StyledRun on every token so style and width stay exact.
     *
     * @param list<StyledRun> $runs
     * @return list<array{run: StyledRun, text: string, width: float, isSpace: bool}>
     */
    private function tokenise(array $runs): array
    {
        $tokens = [];

        foreach ($runs as $run) {
            if ($run->text === '') {
                continue;
            }

            // Keep the space delimiters as their own tokens.
            $parts = preg_split('/( )/', $run->text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            if ($parts === false) {
                continue;
            }

            foreach ($parts as $part) {
                $isSpace = $part === ' ';
                $tokens[] = [
                    'run' => $run,
                    'text' => $part,
                    'width' => ($this->measure)($part, $run->font, $run->sizePt),
                    'isSpace' => $isSpace,
                ];
            }
        }

        return $tokens;
    }

    /**
     * Group consecutive tokens sharing the SAME StyledRun instance into one
     * PositionedSegment (concatenated text, summed width), assigning each
     * segment its left-edge offset accumulated along the line.
     *
     * @param list<array{run: StyledRun, text: string, width: float, isSpace: bool}> $tokens
     */
    private function buildLine(array $tokens): Line
    {
        /** @var list<PositionedSegment> $segments */
        $segments = [];
        $xOffset = 0.0;
        $maxSizePt = 0.0;

        $groupRun = null;
        $groupText = '';
        $groupWidth = 0.0;
        $groupStart = 0.0;

        foreach ($tokens as $token) {
            if ($groupRun !== null && $token['run'] !== $groupRun) {
                $segments[] = $this->makeSegment($groupRun, $groupText, $groupStart, $groupWidth);
                $groupText = '';
                $groupWidth = 0.0;
                $groupStart = $xOffset;
            }

            if ($groupRun === null || $token['run'] !== $groupRun) {
                $groupRun = $token['run'];
                $groupStart = $xOffset;
            }

            $groupText .= $token['text'];
            $groupWidth += $token['width'];
            $xOffset += $token['width'];
            $maxSizePt = max($maxSizePt, $token['run']->sizePt);
        }

        if ($groupRun !== null) {
            $segments[] = $this->makeSegment($groupRun, $groupText, $groupStart, $groupWidth);
        }

        return new Line($segments, $maxSizePt * self::LINE_HEIGHT_FACTOR);
    }

    private function makeSegment(StyledRun $run, string $text, float $xOffsetPt, float $widthPt): PositionedSegment
    {
        $placed = new StyledRun($text, $run->font, $run->color, $run->sizePt, $run->isCode, $run->url);

        return new PositionedSegment($placed, $xOffsetPt, $widthPt);
    }
}
