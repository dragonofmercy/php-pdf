<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontMetrics;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use PHPUnit\Framework\TestCase;

final class MetricsRegistryTest extends TestCase
{
    public function testLoadsHelvetica(): void
    {
        $registry = new MetricsRegistry();
        $m = $registry->metricsFor(Font::helvetica());
        self::assertInstanceOf(FontMetrics::class, $m);
        // Helvetica space glyph is 278/1000 em (well-known Adobe value).
        self::assertSame(278, $m->widths[0x20] ?? null);
    }

    public function testLoadsAllTwelveVariants(): void
    {
        $registry = new MetricsRegistry();
        $variants = [
            Font::helvetica(), Font::helvetica()->bold(),
            Font::helvetica()->italic(), Font::helvetica()->bold()->italic(),
            Font::times(), Font::times()->bold(),
            Font::times()->italic(), Font::times()->bold()->italic(),
            Font::courier(), Font::courier()->bold(),
            Font::courier()->italic(), Font::courier()->bold()->italic(),
        ];
        foreach ($variants as $font) {
            $m = $registry->metricsFor($font);
            self::assertInstanceOf(FontMetrics::class, $m, $font->pdfName());
        }
    }

    public function testCachesPerFont(): void
    {
        $registry = new MetricsRegistry();
        $a = $registry->metricsFor(Font::helvetica());
        $b = $registry->metricsFor(Font::helvetica());
        self::assertSame($a, $b, 'Same Font should return cached FontMetrics instance');
    }

    public function testCourierIsMonospaceWidth600(): void
    {
        $registry = new MetricsRegistry();
        $m = $registry->metricsFor(Font::courier());
        // Sample several bytes; all should be 600 in Courier.
        self::assertSame(600, $m->widths[0x20] ?? null);
        self::assertSame(600, $m->widths[0x41] ?? null);
        self::assertSame(600, $m->widths[0x7A] ?? null);
    }

    public function testThrowsOnMissingFile(): void
    {
        // Point at a non-existent metrics directory.
        $registry = new MetricsRegistry(__DIR__ . '/__nonexistent__');
        $this->expectException(PdfException::class);
        $registry->metricsFor(Font::helvetica());
    }
}
