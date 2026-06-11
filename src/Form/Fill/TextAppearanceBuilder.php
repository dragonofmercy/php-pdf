<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;

/**
 * Builds the content stream body for a filled text field appearance (Form XObject).
 *
 * Returns an array with:
 *   - 'content' (string): the raw PDF content stream operators.
 *   - 'bbox'    (array{0:float,1:float,2:float,3:float}): [llx, lly, urx, ury].
 *
 * @internal
 */
final class TextAppearanceBuilder
{
    public function __construct(private readonly MetricsRegistry $metrics) {}

    /**
     * @param int $quadding 0=left, 1=centre, 2=right.
     * @return array{content: string, bbox: array{0: float, 1: float, 2: float, 3: float}}
     */
    public function build(
        string $text,
        float $widthPt,
        float $heightPt,
        DefaultAppearance $da,
        Font $font,
        string $fontAlias,
        int $quadding,
        bool $multiline,
    ): array {
        // Font size resolution:
        // - Auto-size (size == 0): single-line uses a heuristic that fits the box height with
        //   2pt top+bottom padding, clamped to [4, 12] so very tall or very short boxes still
        //   produce a legible glyph size; multiline falls back to a fixed 10pt.
        // - Otherwise use the size from the DefaultAppearance string verbatim.
        if ($da->isAutoSize()) {
            $size = $multiline ? 10.0 : max(4.0, min(12.0, $heightPt - 4.0));
        } else {
            $size = $da->size;
        }

        $n = static fn(float $v): string => PdfNumber::ofFloat($v)->toBytes();

        $padX = 2.0;
        // Clip rect: inset by 1 pt on each side.
        $clipW = $widthPt - 2.0;
        $clipH = $heightPt - 2.0;

        $lines = [];
        $lines[] = '/Tx BMC';
        $lines[] = 'q';
        $lines[] = '1 1 ' . $n($clipW) . ' ' . $n($clipH) . ' re';
        $lines[] = 'W n';

        if ($multiline) {
            $this->buildMultiline($lines, $text, $widthPt, $heightPt, $da, $font, $fontAlias, $size, $padX, $n);
        } else {
            $this->buildSingleLine($lines, $text, $widthPt, $heightPt, $da, $font, $fontAlias, $quadding, $size, $padX, $n);
        }

        $lines[] = 'Q';
        $lines[] = 'EMC';

        return [
            'content' => implode("\n", $lines),
            'bbox' => [0.0, 0.0, $widthPt, $heightPt],
        ];
    }

    /**
     * Appends BT...ET operators for a single-line field.
     * Quadding 0=left, 1=centre, 2=right.
     *
     * @param list<string> $lines
     * @param \Closure(float): string $n
     */
    private function buildSingleLine(
        array &$lines,
        string $text,
        float $widthPt,
        float $heightPt,
        DefaultAppearance $da,
        Font $font,
        string $fontAlias,
        int $quadding,
        float $size,
        float $padX,
        \Closure $n,
    ): void {
        $encoded = WinAnsiEncoder::encode($text);
        $escaped = PdfLiteralEscape::escape($encoded);

        // Approximate vertical centring: baseline sits at mid-height shifted by ~20% of size.
        $ty = ($heightPt - $size) / 2.0 + $size * 0.2;

        // Horizontal offset based on quadding.
        $tx = $this->resolveX($widthPt, $padX, $encoded, $font, $size, $quadding);

        $lines[] = 'BT';
        if ($da->colorOps !== '') {
            $lines[] = $da->colorOps;
        }
        $lines[] = '/' . $fontAlias . ' ' . $n($size) . ' Tf';
        if ($encoded !== '') {
            $lines[] = $n($tx) . ' ' . $n($ty) . ' Td';
            $lines[] = '(' . $escaped . ') Tj';
        }
        $lines[] = 'ET';
    }

    /**
     * Appends BT...ET operators for a multiline field.
     * Greedy word-wrap. Alignment is always left; quadding is not applied.
     *
     * @param list<string> $lines
     * @param \Closure(float): string $n
     */
    private function buildMultiline(
        array &$lines,
        string $text,
        float $widthPt,
        float $heightPt,
        DefaultAppearance $da,
        Font $font,
        string $fontAlias,
        float $size,
        float $padX,
        \Closure $n,
    ): void {
        $maxWidth = $widthPt - 2.0 * $padX;
        // Line leading: 1.15x font size.
        $leading = $size * 1.15;
        // Top baseline: inset by padX from top, then descend by one font size.
        $yTop = $heightPt - $padX - $size;

        $wrapped = $this->wordWrap($text, $font, $size, $maxWidth);

        $lines[] = 'BT';
        if ($da->colorOps !== '') {
            $lines[] = $da->colorOps;
        }
        $lines[] = '/' . $fontAlias . ' ' . $n($size) . ' Tf';

        if ($wrapped !== []) {
            $lines[] = $n($leading) . ' TL';
            $lines[] = $n($padX) . ' ' . $n($yTop) . ' Td';
            $lines[] = '(' . PdfLiteralEscape::escape(WinAnsiEncoder::encode($wrapped[0])) . ') Tj';
            foreach (array_slice($wrapped, 1) as $wrappedLine) {
                $lines[] = 'T*';
                $lines[] = '(' . PdfLiteralEscape::escape(WinAnsiEncoder::encode($wrappedLine)) . ') Tj';
            }
        }

        $lines[] = 'ET';
    }

    /**
     * Greedy word-wrap: splits $text on spaces into visual lines no wider than $maxWidthPt.
     * A single word that exceeds the width always occupies its own line (no infinite loop).
     *
     * @return list<string>
     */
    private function wordWrap(string $text, Font $font, float $size, float $maxWidthPt): array
    {
        if ($text === '') {
            return [];
        }

        $metrics = $this->metrics->metricsFor($font);
        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
                continue;
            }

            $candidate = $current . ' ' . $word;
            $tw = $metrics->stringWidth(WinAnsiEncoder::encode($candidate), $size);

            if ($tw <= $maxWidthPt) {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Resolves the X offset for single-line text based on quadding.
     * quadding: 0=left, 1=centre, 2=right.
     */
    private function resolveX(float $widthPt, float $padX, string $encoded, Font $font, float $size, int $quadding): float
    {
        if ($quadding === 0 || $encoded === '') {
            return $padX;
        }

        $tw = $this->metrics->metricsFor($font)->stringWidth($encoded, $size);

        return match ($quadding) {
            1 => ($widthPt - $tw) / 2.0,
            2 => $widthPt - $padX - $tw,
            default => $padX,
        };
    }
}
