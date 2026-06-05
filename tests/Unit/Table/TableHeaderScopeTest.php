<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Tagging\TableScope;
use PHPUnit\Framework\TestCase;

final class TableHeaderScopeTest extends TestCase
{
    public function testHeaderCellsCarryColumnScopeAndBodyCellsDoNot(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->table(
            columns: [Column::of('name', 'Nom')->fill(), Column::of('price', 'Prix')->width(30.0)],
            rows: [['name' => 'Cafe', 'price' => '2.50']],
            x: 20.0, y: 30.0, width: 170.0,
        );

        $tree = $doc->structureTree();
        self::assertNotNull($tree);

        $ths = $this->collect($tree->root(), StructureType::TH);
        self::assertCount(2, $ths, 'Two column headers');
        foreach ($ths as $th) {
            self::assertSame(TableScope::Column, $th->scope());
        }

        $tds = $this->collect($tree->root(), StructureType::TD);
        self::assertCount(2, $tds, 'Two body cells');
        foreach ($tds as $td) {
            self::assertNull($td->scope(), 'Body TD cells carry no scope');
        }
    }

    public function testTableInsideArtifactScopeProducesNoTableStructure(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);

        $page->withArtifactScope(function () use ($page): void {
            $page->table(
                columns: [Column::of('name', 'Nom')->fill()],
                rows: [['name' => 'Cafe']],
                x: 20.0, y: 30.0, width: 170.0,
            );
        });

        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        foreach ([StructureType::Table, StructureType::TR, StructureType::TH, StructureType::TD] as $type) {
            self::assertCount(0, $this->collect($tree->root(), $type), $type->value . ' must not be emitted inside an artifact scope');
        }

        // The table content is inside the artifact bracket.
        self::assertStringContainsString('/Artifact BDC', $page->contentStream()->bytes());
    }

    /**
     * @return list<StructElem>
     */
    private function collect(StructElem $elem, StructureType $type): array
    {
        $out = [];
        if ($elem->type() === $type) {
            $out[] = $elem;
        }
        foreach ($elem->children() as $child) {
            if ($child instanceof StructElem) {
                $out = [...$out, ...$this->collect($child, $type)];
            }
        }
        return $out;
    }
}
