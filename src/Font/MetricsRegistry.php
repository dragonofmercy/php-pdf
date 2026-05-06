<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;

/**
 * Lazy-loading registry mapping `Font` instances to their `FontMetrics`
 * value object. One instance is owned by `Document` and shared across all
 * pages so the same font is parsed once per document.
 *
 * @internal
 */
final class MetricsRegistry
{
    /** @var array<string, FontMetrics> indexed by Font::pdfName() */
    private array $cache = [];

    private readonly string $metricsDir;

    public function __construct(?string $metricsDir = null)
    {
        $this->metricsDir = $metricsDir ?? __DIR__ . '/Metrics';
    }

    public function metricsFor(Font $font): FontMetrics
    {
        $pdfName = $font->pdfName();
        return $this->cache[$pdfName] ??= $this->load($pdfName);
    }

    private function load(string $pdfName): FontMetrics
    {
        $fileName = match ($pdfName) {
            'Helvetica' => 'Helvetica',
            'Helvetica-Bold' => 'HelveticaBold',
            'Helvetica-Oblique' => 'HelveticaOblique',
            'Helvetica-BoldOblique' => 'HelveticaBoldOblique',
            'Times-Roman' => 'TimesRoman',
            'Times-Bold' => 'TimesBold',
            'Times-Italic' => 'TimesItalic',
            'Times-BoldItalic' => 'TimesBoldItalic',
            'Courier' => 'Courier',
            'Courier-Bold' => 'CourierBold',
            'Courier-Oblique' => 'CourierOblique',
            'Courier-BoldOblique' => 'CourierBoldOblique',
            default => throw new PdfException("Unsupported font for metrics: {$pdfName}"),
        };

        $path = $this->metricsDir . '/' . $fileName . '.php';
        if (!is_file($path)) {
            throw new PdfException("Font metrics file not found: {$path}");
        }

        /**
         * @var array{ascent:int,descent:int,capHeight:int,xHeight:int,missingWidth:int,widths:array<int,int>} $data
         */
        $data = require $path;

        return new FontMetrics(
            ascent: $data['ascent'],
            descent: $data['descent'],
            capHeight: $data['capHeight'],
            xHeight: $data['xHeight'],
            missingWidth: $data['missingWidth'],
            widths: $data['widths'],
        );
    }
}
