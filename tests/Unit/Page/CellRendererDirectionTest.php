<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class CellRendererDirectionTest extends TestCase
{
    public function testRtlHebrewRendersWithoutError(): void
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/../../Golden/assets/fonts/FreeSans.ttf');
        $doc->setBaseDirection(Direction::RTL);
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14);
        $page->cell(x: 20, y: 20, w: 80, h: 10, text: "\u{05D0}\u{05D1}\u{05D2}");
        $pdf = $doc->output();

        self::assertStringContainsString('Tj', $pdf);
        self::assertGreaterThan(800, strlen($pdf));
    }

    public function testLtrLatinUnaffectedByExplicitLtr(): void
    {
        $a = (static function (): string {
            $doc = new Document(Unit::MM);
            $page = $doc->addPage();
            $page->setFont(Font::helvetica(), 12);
            $page->cell(x: 20, y: 20, w: 80, h: 10, text: 'Hello');
            return $doc->output();
        })();

        $b = (static function (): string {
            $doc = new Document(Unit::MM);
            $doc->setBaseDirection(Direction::LTR);
            $page = $doc->addPage();
            $page->setFont(Font::helvetica(), 12);
            $page->cell(x: 20, y: 20, w: 80, h: 10, text: 'Hello');
            return $doc->output();
        })();

        self::assertSame($a, $b);
    }
}
