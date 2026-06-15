<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Form\Fill\Font\AppearanceFont;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;

/**
 * Builds the content stream body for a listbox field appearance (Form XObject).
 *
 * Returns an array with:
 *   - 'content' (string): the raw PDF content stream operators.
 *   - 'bbox'    (array{0:float,1:float,2:float,3:float}): [llx, lly, urx, ury].
 *
 * @internal
 */
final class ListboxAppearanceBuilder
{
    /**
     * @param list<string> $displayOptions  Display text for each option (in order).
     * @param list<int>    $selectedIndices Indices (0-based) of selected options, sorted ascending.
     * @return array{content: string, bbox: array{0: float, 1: float, 2: float, 3: float}}
     */
    public function build(
        array $displayOptions,
        array $selectedIndices,
        float $w,
        float $h,
        DefaultAppearance $da,
        string $alias,
        AppearanceFont $font,
    ): array {
        $size = $da->isAutoSize() ? 10.0 : $da->size;
        $lineH = $size * 1.15;
        $padX = 1.0;

        $n = static fn(float $v): string => PdfNumber::ofFloat($v)->toBytes();

        $selectedSet = array_flip($selectedIndices);

        $lines = [];
        $lines[] = '/Tx BMC';
        $lines[] = 'q';
        // Clip inset by 1pt on each side
        $lines[] = '1 1 ' . $n($w - 2.0) . ' ' . $n($h - 2.0) . ' re';
        $lines[] = 'W n';

        // Draw highlight backgrounds for selected rows before text
        foreach ($selectedIndices as $idx) {
            $lineBottomY = $h - ($idx + 1) * $lineH;
            $lines[] = '0.6 0.847 1 rg';
            $lines[] = '0 ' . $n($lineBottomY) . ' ' . $n($w) . ' ' . $n($lineH) . ' re f';
        }

        // BT block: one Tm+Tj per option
        $lines[] = 'BT';
        if ($da->colorOps !== '') {
            $lines[] = $da->colorOps;
        }
        $lines[] = '/' . $alias . ' ' . $n($size) . ' Tf';

        foreach ($displayOptions as $i => $displayText) {
            $lineBottomY = $h - ($i + 1) * $lineH;
            $baselineY = $lineBottomY + 0.25 * $size;
            $lines[] = '1 0 0 1 ' . $n($padX) . ' ' . $n($baselineY) . ' Tm';
            $lines[] = $font->encodeShowOperand($displayText) . ' Tj';
        }

        $lines[] = 'ET';
        $lines[] = 'Q';
        $lines[] = 'EMC';

        return [
            'content' => implode("\n", $lines),
            'bbox' => [0.0, 0.0, $w, $h],
        ];
    }
}
