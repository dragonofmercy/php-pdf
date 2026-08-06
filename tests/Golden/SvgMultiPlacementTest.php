<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgMultiPlacementTest extends TestCase
{
    public function testSvgMultiPlacementMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/basic/multi-placement.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgMultiPlacementPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/basic/multi-placement.pdf');
    }

    public function testSvgMultiPlacementDeduplicatesFormXObject(): void
    {
        $bytes = self::buildPdfBytes();
        // One SVG instance -> one Form XObject definition (/Subtype /Form)
        self::assertSame(1, substr_count($bytes, '/Subtype /Form'), 'Expected exactly 1 Form XObject (dedup by Image instance)');
        // Three placements -> three Do invocations; the content stream is compressed,
        // so we count references to the XObject name in the resource dictionary + stream.
        // Each placement emits "q ... /Im<n> Do Q"; the resource dict has exactly one /Im<n> key.
        // We verify by counting the total number of " Do" occurrences across all raw bytes
        // after decompressing each FlateDecode stream.
        $doCount = 0;
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches);
        foreach ($matches[1] as $rawStream) {
            $decoded = @gzuncompress($rawStream);
            if ($decoded !== false) {
                $doCount += substr_count($decoded, ' Do');
            }
        }
        self::assertSame(3, $doCount, 'Expected exactly 3 Do invocations (one per image() call)');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        // Star shape - non-trivial path to exercise the renderer
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<path d="M 50 10 L 61 35 L 90 35 L 67 55 L 76 80 L 50 62 L 24 80 L 33 55 L 10 35 L 39 35 Z" fill="gold" stroke="black" stroke-width="2"/>'
            . '</svg>';
        // Reuse the same Image instance so the embedder can dedup it
        $img = Image::fromBytes($svg);
        $page->image($img, x: 50.0, y: 50.0, w: 100.0);
        $page->image($img, x: 200.0, y: 50.0, w: 150.0);
        $page->image($img, x: 100.0, y: 250.0, w: 200.0);
        return $doc->output();
    }
}
