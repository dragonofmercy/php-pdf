<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Tagging\TaggingConformanceGuard;
use PHPUnit\Framework\TestCase;

final class TaggingConformanceGuardTest extends TestCase
{
    public function testRejectsStandardFonts(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('embedded');
        (new TaggingConformanceGuard())->verify(
            standardFonts: [Font::helvetica()],
            title: 'T',
            tree: new StructureTree(),
            hasUntaggedLinkAnnotations: false,
        );
    }

    public function testRejectsMissingTitle(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('title');
        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: null,
            tree: new StructureTree(),
            hasUntaggedLinkAnnotations: false,
        );
    }

    public function testRejectsEmptyTitle(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('title');
        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: '',
            tree: new StructureTree(),
            hasUntaggedLinkAnnotations: false,
        );
    }

    public function testRejectsFigureWithoutAlt(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::Figure);
        $tree->close();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('alternate text');
        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'T',
            tree: $tree,
            hasUntaggedLinkAnnotations: false,
        );
    }

    public function testRejectsUntaggedLinkAnnotations(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('cell(link:');
        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'T',
            tree: new StructureTree(),
            hasUntaggedLinkAnnotations: true,
        );
    }

    public function testAcceptsTaggedLinksWhenNoUntaggedAnnotationsPresent(): void
    {
        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'T',
            tree: new StructureTree(),
            hasUntaggedLinkAnnotations: false,
        );
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsSkippedHeadingLevel(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::H1);
        $tree->close();
        $tree->open(StructureType::H3);
        $tree->close();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('skip');
        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'T',
            tree: $tree,
            hasUntaggedLinkAnnotations: false,
        );
    }

    public function testPassesOnConformantInput(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::H1);
        $tree->close();
        $tree->open(StructureType::H2);
        $tree->close();
        $figure = $tree->open(StructureType::Figure);
        $figure->setAlt('A revenue chart');
        $tree->close();

        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'Accessible report',
            tree: $tree,
            hasUntaggedLinkAnnotations: false,
        );
        $this->expectNotToPerformAssertions();
    }

    public function testFirstHeadingNeedNotBeH1WhenStartingAtAllowedLevel(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::H1);
        $tree->close();

        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'T',
            tree: $tree,
            hasUntaggedLinkAnnotations: false,
        );
        $this->expectNotToPerformAssertions();
    }

    public function testRepeatedHeadingLevelsDoNotCountAsSkip(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::H1);
        $tree->close();
        $tree->open(StructureType::H2);
        $tree->close();
        $tree->open(StructureType::H1);
        $tree->close();
        $tree->open(StructureType::H2);
        $tree->close();

        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'T',
            tree: $tree,
            hasUntaggedLinkAnnotations: false,
        );
        $this->expectNotToPerformAssertions();
    }

    public function testHeadingJumpFromH2ToH4Throws(): void
    {
        $tree = new StructureTree();
        $tree->open(StructureType::H1);
        $tree->close();
        $tree->open(StructureType::H2);
        $tree->close();
        $tree->open(StructureType::H4);
        $tree->close();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('skip');
        (new TaggingConformanceGuard())->verify(
            standardFonts: [],
            title: 'T',
            tree: $tree,
            hasUntaggedLinkAnnotations: false,
        );
    }
}
