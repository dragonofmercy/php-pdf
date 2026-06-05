<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use PHPUnit\Framework\TestCase;

final class ImageTaggingTest extends TestCase
{
    private static function asset(): string
    {
        return __DIR__ . '/../../Golden/assets/png-opaque-rgb-24x12.png';
    }

    public function testTaggedImageWithAltCreatesFigureWithAltText(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->image(self::asset(), x: 10, y: 10, w: 20, h: 20, alt: 'A revenue chart');

        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        $figure = $this->firstFigure($tree->root());
        self::assertNotNull($figure);
        self::assertSame('A revenue chart', $figure->alt());
        self::assertStringNotContainsString('/Artifact BMC', $page->contentStream()->bytes());
    }

    public function testDecorativeImageProducesArtifactNotFigure(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->image(self::asset(), x: 10, y: 10, w: 20, h: 20, decorative: true);

        $tree = $doc->structureTree();
        self::assertNotNull($tree);
        self::assertNull($this->firstFigure($tree->root()), 'Decorative image must not produce a Figure element');
        self::assertStringContainsString('/Artifact BMC', $page->contentStream()->bytes());
    }

    public function testAltAndDecorativeTogetherThrow(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('decorative');
        $page->image(self::asset(), alt: 'x', decorative: true);
    }

    public function testWithArtifactScopeBracketsWhenTaggingOn(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        self::assertFalse($page->isArtifactScope());
        $page->withArtifactScope(function () use ($page): void {
            self::assertTrue($page->isArtifactScope());
            $page->rect(0, 0, 10, 10)->fill();
        });
        self::assertFalse($page->isArtifactScope());

        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString('/Artifact BMC', $bytes);
        self::assertStringContainsString('EMC', $bytes);
    }

    public function testWithArtifactScopeIsReentrant(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->withArtifactScope(function () use ($page): void {
            $page->withArtifactScope(function () use ($page): void {
                self::assertTrue($page->isArtifactScope());
                $page->rect(0, 0, 10, 10)->fill();
            });
            self::assertTrue($page->isArtifactScope(), 'Outer scope flag restored after nested call');
        });

        // Nested calls must not double-bracket: exactly one BDC/EMC pair.
        $bytes = $page->contentStream()->bytes();
        self::assertSame(1, substr_count($bytes, '/Artifact BMC'));
    }

    public function testWithArtifactScopeIsNoOpBracketWhenTaggingOff(): void
    {
        $untagged = new Document();
        $pageA = $untagged->addPage();
        $pageA->withArtifactScope(function () use ($pageA): void {
            $pageA->rect(0, 0, 10, 10)->fill();
        });

        $plain = new Document();
        $pageB = $plain->addPage();
        $pageB->rect(0, 0, 10, 10)->fill();

        // Off-path: withArtifactScope must run the body but emit no artifact ops.
        self::assertStringNotContainsString('/Artifact BMC', $pageA->contentStream()->bytes());
        self::assertSame($pageB->contentStream()->bytes(), $pageA->contentStream()->bytes());
    }

    private function firstFigure(StructElem $elem): ?StructElem
    {
        if ($elem->type() === StructureType::Figure) {
            return $elem;
        }
        foreach ($elem->children() as $child) {
            if ($child instanceof StructElem) {
                $found = $this->firstFigure($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}
