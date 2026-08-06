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

    public function metricsFor(Font $font): FontMetrics
    {
        $pdfName = $font->pdfName();
        return $this->cache[$pdfName] ??= $this->load($pdfName);
    }

    private function load(string $pdfName): FontMetrics
    {
        // The 12 metric files are named after their PDF font name minus the
        // hyphen: Times-BoldItalic -> TimesBoldItalic.php. Font::pdfName()
        // only ever yields one of those 12, custom fonts throwing before here.
        $path = __DIR__ . '/Metrics/' . str_replace('-', '', $pdfName) . '.php';
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
