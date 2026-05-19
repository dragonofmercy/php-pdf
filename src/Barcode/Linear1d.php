<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Font, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

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
        $page->contentStream()->append(Renderer::wrap($body, $color));

        if ($humanText !== null) {
            self::drawCenteredText($page, $xPt, $yPt, $wPt, $barsHeight, $textHeight, $color, $humanText);
        }
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
        $fontSize = min(12.0, $wPt / 10.0);
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
