<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use PHPUnit\Framework\TestCase;

final class StructureWellFormedTest extends TestCase
{
    public function testEveryMarkedContentResolvesAndRootIsDocument(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(w: 80, h: 10, text: 'Para one');
        $page->cell(w: 80, h: 10, text: 'Para two');
        $bytes = $doc->output();

        // Struct-tree tokens live in uncompressed dictionary objects: exactly one
        // StructTreeRoot, one Document element, two P elements (one per cell).
        self::assertSame(1, substr_count($bytes, '/Type /StructTreeRoot'));
        self::assertSame(1, substr_count($bytes, '/S /Document'));
        self::assertSame(2, substr_count($bytes, '/S /P'));

        // ParentTree present with a Nums entry whose first key (page 0) maps to an array.
        self::assertStringContainsString('/ParentTree', $bytes);
        self::assertMatchesRegularExpression('#/Nums\s*\[\s*0\s*\[#', $bytes);

        // Marked-content operators live in the Flate-compressed page content stream,
        // so they must be inflated before asserting on them.
        $content = self::inflatedContentStreams($bytes);

        self::assertStringContainsString('/P <</MCID 0>> BDC', $content);
        self::assertStringContainsString('/P <</MCID 1>> BDC', $content);
        self::assertSame(2, substr_count($content, 'BDC'));
        self::assertSame(2, substr_count($content, 'EMC'));
    }

    /**
     * Concatenate every Flate-decoded stream body found in the PDF bytes. Streams
     * that are not Flate-compressed (e.g. the ICC profile) simply fail to inflate
     * and are skipped, leaving the page content streams.
     */
    private static function inflatedContentStreams(string $bytes): string
    {
        $content = '';
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                $inflated = @gzuncompress($raw);
                if ($inflated !== false) {
                    $content .= $inflated;
                }
            }
        }
        return $content;
    }
}
