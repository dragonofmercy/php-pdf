<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class CellDirectionAlignTest extends TestCase
{
    /** Render 'abc' (Latin, so reordering is identity) into one cell. */
    private static function render(?Direction $direction, ?TextAlign $align, ?Direction $docDefault = null): string
    {
        $doc = new Document(Unit::MM);
        if ($docDefault !== null) {
            $doc->setBaseDirection($docDefault);
        }
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 10, w: 100, h: 10, text: 'abc', align: $align, direction: $direction);
        return $doc->output();
    }

    public function testRtlBaseDefaultsToRightAlignment(): void
    {
        // RTL base with no explicit align == explicit RIGHT align (Latin text:
        // reordering is identity, so only the alignment can differ).
        self::assertSame(
            self::render(direction: null, align: TextAlign::RIGHT),
            self::render(direction: Direction::RTL, align: null),
        );
    }

    public function testRtlBaseDiffersFromLeftDefault(): void
    {
        self::assertNotSame(
            self::render(direction: null, align: TextAlign::LEFT),
            self::render(direction: Direction::RTL, align: null),
        );
    }

    public function testExplicitAlignWinsOverRtlBase(): void
    {
        // Even with RTL base, an explicit LEFT alignment is honored.
        self::assertSame(
            self::render(direction: null, align: TextAlign::LEFT),
            self::render(direction: Direction::RTL, align: TextAlign::LEFT),
        );
    }

    public function testDocumentDefaultDirectionApplies(): void
    {
        // Document base RTL (no per-call direction) right-aligns by default,
        // differing from a plain LTR document.
        self::assertSame(
            self::render(direction: null, align: TextAlign::RIGHT),
            self::render(direction: null, align: null, docDefault: Direction::RTL),
        );
        self::assertNotSame(
            self::render(direction: null, align: null),
            self::render(direction: null, align: null, docDefault: Direction::RTL),
        );
    }
}
