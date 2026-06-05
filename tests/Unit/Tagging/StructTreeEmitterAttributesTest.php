<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Tagging\StructTreeEmitter;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Tagging\TableScope;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use PHPUnit\Framework\TestCase;

final class StructTreeEmitterAttributesTest extends TestCase
{
    public function testFigureAltAndThScopeAreEmitted(): void
    {
        $tree = new StructureTree();
        $figure = $tree->open(StructureType::Figure);
        $figure->setAlt('Revenue chart');
        $tree->addMarkedContent(0, 0);
        $tree->close();
        $th = $tree->open(StructureType::TH);
        $th->setScope(TableScope::Column);
        $tree->addMarkedContent(0, 1);
        $tree->close();

        $pageRef = PdfReference::to(1, 0);
        /** @var \SplObjectStorage<\DragonOfMercy\PhpPdf\Outline\LinkAnnotation, int> $emptyMap */
        $emptyMap = new \SplObjectStorage();
        $result = (new StructTreeEmitter())->emit($tree, [$pageRef], $emptyMap, 10);

        $dump = '';
        foreach ($result->objects as $obj) {
            $dump .= $obj->toBytes();
        }

        // /Alt carries the alternate text, encoded as a UTF-16BE hex text string.
        self::assertStringContainsString('/Alt', $dump);
        self::assertStringContainsString(TextString::of('Revenue chart')->toBytes(), $dump);

        // /A <</O /Table /Scope /Column>> on the TH element.
        self::assertStringContainsString('/O /Table', $dump);
        self::assertStringContainsString('/Scope /Column', $dump);
    }
}
