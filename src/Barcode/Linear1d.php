<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Font, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Page\Operators;

/**
 * Shared rendering pipeline for the generic 1D barcodes (Code 39, Code 93,
 * ITF): unit->points conversion, quiet-zone padding, run-length bar fill
 * wrapped in q..f Q, and an optional centered human-readable text band.
 *
 * Not used by EAN/UPC-A (those have format-specific human-text layouts).
 *
 * @internal
 */
final class Linear1d
{
    /**
     * @param list<bool> $modules symbol modules WITHOUT the quiet zone
     * @param int $quietModules quiet-zone width in modules, added on both sides
     * @param ?float $bearerBarModules when set, draw a full-frame bearer bar of
     *     this thickness (in modules) around the symbol; null draws none
     */
    public static function draw(
        Page $page,
        float $x,
        float $y,
        float $w,
        ?float $h,
        array $modules,
        int $quietModules,
        Color $color,
        ?string $humanText,
        string $formatName,
        ?float $bearerBarModules = null,
        Orientation $orientation = Orientation::Horizontal,
    ): void {
        if ($h === null) {
            throw new PdfException("{$formatName} requires explicit h (height)");
        }

        if ($humanText === '') {
            $humanText = null;
        }

        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $hPt = $unit->toPoints($h);

        Renderer::oriented($page, $orientation, $xPt, $yPt, $wPt, $hPt, function () use ($page, $xPt, $yPt, $wPt, $hPt, $modules, $quietModules, $color, $humanText, $bearerBarModules): void {
            $totalModules = $quietModules * 2 + count($modules);
            $moduleW = $wPt / $totalModules;

            if ($humanText !== null) {
                $barsHeight = $hPt * 0.85;
                $textHeight = $hPt - $barsHeight;
            } else {
                $barsHeight = $hPt;
                $textHeight = 0.0;
            }

            $padded = array_merge(
                array_fill(0, $quietModules, false),
                $modules,
                array_fill(0, $quietModules, false),
            );

            $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);
            if ($bearerBarModules !== null) {
                $body .= self::bearerFrame($xPt, $yPt, $wPt, $barsHeight, $bearerBarModules * $moduleW);
            }
            $page->contentStream()->append(Renderer::wrap($body, $color));

            if ($humanText !== null) {
                self::drawCenteredText($page, $xPt, $yPt, $wPt, $barsHeight, $textHeight, $color, $humanText);
            }
        });
    }

    /**
     * Four `re` rects forming a GS1 full-frame bearer bar around the bar area:
     * top and bottom span the full width (quiet zones included), left and right
     * span the bar height. Same fill as the bars, so it joins them visually.
     */
    private static function bearerFrame(float $xPt, float $yPt, float $wPt, float $barsHeight, float $t): string
    {
        return Operators::rectangle($xPt, $yPt, $wPt, $t)
            . Operators::rectangle($xPt, $yPt + $barsHeight - $t, $wPt, $t)
            . Operators::rectangle($xPt, $yPt, $t, $barsHeight)
            . Operators::rectangle($xPt + $wPt - $t, $yPt, $t, $barsHeight);
    }

    private static function drawCenteredText(
        Page $page,
        float $xPt,
        float $yPt,
        float $wPt,
        float $barsHeight,
        float $textHeight,
        Color $color,
        string $text,
    ): void {
        // Cap fontSize at textHeight so cap-height (~70% of fontSize) leaves
        // ~15% of the text band as gap above and below the glyphs - otherwise
        // a 12pt cap glues the text against the bars when the band is small.
        $fontSize = min(12.0, $wPt / 10.0, $textHeight);
        $textY = $yPt + $barsHeight + ($textHeight - $fontSize * 0.7) / 2 + $fontSize * 0.7;
        $textYUnit = $page->unit->fromPoints($textY);

        $page->save();
        $page->setFillColor($color);
        $page->setFont(Font::helvetica(), $fontSize);

        $startXUnit = $page->unit->fromPoints($xPt);
        $fullWidthUnit = $page->unit->fromPoints($wPt);
        $textWidth = $page->stringWidth($text);
        $textX = $startXUnit + ($fullWidthUnit - $textWidth) / 2;
        $page->text($textX, $textYUnit, $text);

        $page->restore();
    }
}
