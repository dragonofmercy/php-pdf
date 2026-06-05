<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ListMarkerArtifactTest extends TestCase
{
    public function testListMarkerIsBracketedAndBodyIsStructured(): void
    {
        $doc = new Document(Unit::PT);
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->markdown("- Alpha\n- Beta", x: 10, y: 10, width: 300);

        $bytes = $page->contentStream()->bytes();

        // One artifact bracket per list-item marker (two items); every BDC is
        // closed by an EMC (artifact markers + the /P body marked content).
        self::assertSame(2, substr_count($bytes, '/Artifact BDC'));
        self::assertSame(substr_count($bytes, 'BDC'), substr_count($bytes, 'EMC'));

        // The list body text stays inside LI/LBody structure, not an artifact.
        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        self::assertTrue($this->hasType($tree->root(), StructureType::LBody), 'List body must be tagged LBody');
        self::assertTrue($this->hasType($tree->root(), StructureType::LI), 'List item must be tagged LI');
    }

    public function testListMarkerNotBracketedWhenTaggingOff(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->markdown("- Alpha\n- Beta", x: 10, y: 10, width: 300);

        self::assertStringNotContainsString('/Artifact BDC', $page->contentStream()->bytes());
    }

    private function hasType(StructElem $elem, StructureType $type): bool
    {
        if ($elem->type() === $type) {
            return true;
        }
        foreach ($elem->children() as $child) {
            if ($child instanceof StructElem && $this->hasType($child, $type)) {
                return true;
            }
        }
        return false;
    }
}
