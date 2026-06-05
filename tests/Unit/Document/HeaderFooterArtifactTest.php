<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use PHPUnit\Framework\TestCase;

final class HeaderFooterArtifactTest extends TestCase
{
    public function testHeaderAndFooterEmitArtifactsAndNoStructure(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $doc->setHeader(function (Page $page): void {
            $page->setFont(Font::helvetica(), 10);
            $page->cell(x: 10, y: 5, w: 80, h: 8, text: 'Header text');
        });
        $doc->setFooter(function (Page $page, int $current, int $total): void {
            $page->setFont(Font::helvetica(), 10);
            $page->text(10, 280, "Page {$current}/{$total}");
        });
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 50, w: 80, h: 8, text: 'Body');
        $doc->output();

        // Only the body cell produces structure: a single P element.
        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        self::assertSame(1, $this->countType($tree->root(), StructureType::P), 'Header/footer must emit no structure');

        // Header and footer content is bracketed as artifacts.
        $bytes = $page->contentStream()->bytes();
        self::assertGreaterThanOrEqual(1, substr_count($bytes, '/Artifact BDC'));
        self::assertStringContainsString('EMC', $bytes);
    }

    private function countType(StructElem $elem, StructureType $type): int
    {
        $count = $elem->type() === $type ? 1 : 0;
        foreach ($elem->children() as $child) {
            if ($child instanceof StructElem) {
                $count += $this->countType($child, $type);
            }
        }
        return $count;
    }
}
