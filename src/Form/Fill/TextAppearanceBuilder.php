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
 * This task implements the single-line, left-aligned, fixed-size path only.
 * Alignment, auto-size, and multiline are handled in Task 6.
 *
 * @internal
 */
final class TextAppearanceBuilder
{
    public function __construct(private readonly MetricsRegistry $metrics) {}

    /** Returns the metrics registry (used by Task 6 for auto-size and alignment). */
    public function metrics(): MetricsRegistry
    {
        return $this->metrics;
    }

    /**
     * @param int $quadding 0=left, 1=centre, 2=right (reserved for Task 6).
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
        $size = $da->size; // assume non-zero; Task 6 handles auto-size

        $encoded = WinAnsiEncoder::encode($text);

        // Escape backslash first, then parens, for a PDF literal string.
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);

        $n = static fn(float $v): string => PdfNumber::ofFloat($v)->toBytes();

        $padX = 2.0;
        // Approximate vertical centring: baseline sits at mid-height shifted by ~20% of size.
        $ty = ($heightPt - $size) / 2.0 + $size * 0.2;

        // Clip rect: inset by 1 pt on each side.
        $clipW = $widthPt - 2.0;
        $clipH = $heightPt - 2.0;

        $lines = [];
        $lines[] = '/Tx BMC';
        $lines[] = 'q';
        $lines[] = '1 1 ' . $n($clipW) . ' ' . $n($clipH) . ' re';
        $lines[] = 'W n';
        $lines[] = 'BT';
        if ($da->colorOps !== '') {
            $lines[] = $da->colorOps;
        }
        $lines[] = '/' . $fontAlias . ' ' . $n($size) . ' Tf';
        if ($encoded !== '') {
            $lines[] = $n($padX) . ' ' . $n($ty) . ' Td';
            $lines[] = '(' . $escaped . ') Tj';
        }
        $lines[] = 'ET';
        $lines[] = 'Q';
        $lines[] = 'EMC';

        $content = implode("\n", $lines);

        return [
            'content' => $content,
            'bbox' => [0.0, 0.0, $widthPt, $heightPt],
        ];
    }
}
