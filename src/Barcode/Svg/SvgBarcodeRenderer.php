<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Svg;

use DragonOfMercy\PhpPdf\Barcode\Barcode;
use DragonOfMercy\PhpPdf\Barcode\BarcodeKind;
use DragonOfMercy\PhpPdf\Barcode\EncodedBarcode;
use DragonOfMercy\PhpPdf\Barcode\Orientation;
use DragonOfMercy\PhpPdf\Barcode\RunLength;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Standalone SVG renderer for any Barcode. Outputs a self-contained SVG string
 * (or a base64 data URI) that any browser/viewer can render without PhpPdf.
 */
final class SvgBarcodeRenderer
{
    public function __construct(
        private bool $background = true,
    ) {}

    public function withoutBackground(): self
    {
        return new self(background: false);
    }

    public function render(Barcode $barcode, int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            throw new PdfException("SvgBarcodeRenderer requires positive width and height, got {$width}x{$height}");
        }
        $encoded = $barcode->encode();
        return match ($encoded->kind) {
            BarcodeKind::LINEAR_1D => $this->render1d($encoded, $width, $height),
            BarcodeKind::MATRIX_2D => $this->render2d($encoded, $width, $height),
        };
    }

    public static function renderDataUri(Barcode $barcode, int $width, int $height): string
    {
        $svg = (new self())->render($barcode, $width, $height);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * 1D rendering: viewBox X = total modules (incl. quiet zone), viewBox Y is
     * derived so the requested pixel aspect (height/width) is preserved.
     */
    private function render1d(EncodedBarcode $encoded, int $width, int $height): string
    {
        /** @var list<bool> $row */
        $row = $encoded->modules;
        $totalModules = count($row);
        $viewBoxH = $totalModules * ($height / $width);

        $hasText = $encoded->humanTextSegments !== [];
        $barsH = $hasText ? $viewBoxH * 0.85 : $viewBoxH;
        $bearerH = $encoded->bearerBarModules;
        $color = self::svgColor($encoded->color);

        // Foreground content (rects + bearer bar + text). Built without bg/SVG wrapping.
        $inner = '';
        foreach (RunLength::runLengths($row) as [$start, $len]) {
            $inner .= sprintf(
                '<rect x="%s" y="0" width="%s" height="%s" fill="%s"/>',
                self::fmt((float) $start), self::fmt((float) $len), self::fmt($barsH), $color,
            );
        }
        if ($bearerH !== null) {
            $tm = (float) $totalModules;
            $inner .= sprintf('<rect x="0" y="0" width="%s" height="%s" fill="%s"/>', self::fmt($tm), self::fmt($bearerH), $color);
            $inner .= sprintf('<rect x="0" y="%s" width="%s" height="%s" fill="%s"/>', self::fmt($barsH - $bearerH), self::fmt($tm), self::fmt($bearerH), $color);
            $inner .= sprintf('<rect x="0" y="0" width="%s" height="%s" fill="%s"/>', self::fmt($bearerH), self::fmt($barsH), $color);
            $inner .= sprintf('<rect x="%s" y="0" width="%s" height="%s" fill="%s"/>', self::fmt($tm - $bearerH), self::fmt($bearerH), self::fmt($barsH), $color);
        }
        $textBandH = $viewBoxH - $barsH;
        foreach ($encoded->humanTextSegments as $seg) {
            // fontSizeModule = 0.0 is the "fill the text band" sentinel:
            // the renderer picks ~70% of the available band height so the
            // glyphs sit inside the band with breathing room above/below.
            // A positive value is the encoder's explicit choice, capped at
            // textBandH so it never overflows.
            $fontSize = $seg->fontSizeModule > 0.0
                ? ($textBandH > 0.0 ? min($seg->fontSizeModule, $textBandH) : $seg->fontSizeModule)
                : ($textBandH > 0.0 ? $textBandH * 0.7 : 0.0);
            $yBaseline = $seg->yModule > 0.0
                ? $seg->yModule
                : $barsH + ($textBandH + $fontSize * 0.7) / 2.0;
            $inner .= sprintf(
                '<text x="%s" y="%s" font-family="Helvetica" font-size="%s" text-anchor="%s" fill="%s">%s</text>',
                self::fmt($seg->xModule), self::fmt($yBaseline), self::fmt($fontSize),
                $seg->anchor->value, $color, self::xmlEscape($seg->text),
            );
        }

        // Wrap with orientation. ROTATION must wrap only the foreground content,
        // NOT the background rect. Background stays in the outer SVG.
        if ($encoded->orientation === Orientation::Vertical) {
            $inner = '<g transform="rotate(-90) translate(' . self::fmt(-$viewBoxH) . ' 0)">' . $inner . '</g>';
        }

        return $this->wrapSvg((float) $totalModules, $viewBoxH, $width, $height, $inner);
    }

    /**
     * 2D rendering: viewBox = cols x rows. One rect per row run.
     */
    private function render2d(EncodedBarcode $encoded, int $width, int $height): string
    {
        /** @var list<list<bool>> $matrix */
        $matrix = $encoded->modules;
        $rows = count($matrix);
        if ($rows === 0) {
            throw new PdfException('2D barcode matrix must be non-empty');
        }
        $cols = count($matrix[0]);
        if ($cols === 0) {
            throw new PdfException('2D barcode matrix must be non-empty');
        }

        $color = self::svgColor($encoded->color);
        $inner = '';
        foreach ($matrix as $rowIndex => $row) {
            foreach (RunLength::runLengths($row) as [$start, $len]) {
                $inner .= sprintf(
                    '<rect x="%s" y="%s" width="%s" height="1" fill="%s"/>',
                    self::fmt((float) $start), self::fmt((float) $rowIndex), self::fmt((float) $len), $color,
                );
            }
        }

        return $this->wrapSvg((float) $cols, (float) $rows, $width, $height, $inner);
    }

    private function wrapSvg(float $viewBoxW, float $viewBoxH, int $widthPx, int $heightPx, string $inner): string
    {
        $bg = '';
        if ($this->background) {
            $bg = sprintf(
                '<rect width="%s" height="%s" fill="#ffffff"/>',
                self::fmt($viewBoxW), self::fmt($viewBoxH),
            );
        }
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %s %s">%s%s</svg>',
            $widthPx, $heightPx,
            self::fmt($viewBoxW), self::fmt($viewBoxH),
            $bg, $inner,
        );
    }

    private static function svgColor(Color $c): string
    {
        [$r, $g, $b] = $c->rgbComponents(); // public accessor returns floats 0..1
        return sprintf('#%02x%02x%02x',
            (int) round($r * 255.0),
            (int) round($g * 255.0),
            (int) round($b * 255.0),
        );
    }

    private static function fmt(float $v): string
    {
        if ($v === (float) (int) $v) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
