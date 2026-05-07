<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that when the document is configured in millimetres, every public
 * coordinate/size on Page is converted to PDF points before reaching the
 * content stream. 10 mm == 28.346457 pt.
 */
final class PageMmTest extends TestCase
{
    private function content(Page $page): string
    {
        $bytes = $page->contentStream()->bytes();
        if ($bytes === '') {
            return '';
        }
        // Strip the Y-flip CTM prefix so assertions focus on operators.
        $newline = strpos($bytes, "\n");
        return $newline === false ? $bytes : substr($bytes, $newline + 1);
    }

    public function testRectIsConvertedToPoints(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->rect(10, 20, 30, 40)->stroke();
        self::assertStringContainsString(
            '28.346457 56.692913 85.03937 113.385827 re',
            $this->content($page),
        );
    }

    public function testLineIsConvertedToPoints(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->line(10, 20, 30, 40)->stroke();
        $body = $this->content($page);
        self::assertStringContainsString('28.346457 56.692913 m', $body);
        self::assertStringContainsString('85.03937 113.385827 l', $body);
    }

    public function testCircleIsConvertedToPoints(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->circle(50, 50, 10)->stroke();
        // Centre 50mm = 141.732283 pt, radius 10mm = 28.346457 pt -> first moveTo at (cx+r, cy)
        self::assertStringContainsString('170.07874 141.732283 m', $this->content($page));
    }

    public function testLineWidthIsConvertedToPoints(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->setLineWidth(0.5);
        // 0.5 mm = 1.417323 pt
        self::assertStringContainsString('1.417323 w', $this->content($page));
    }

    public function testTranslateIsConvertedToPoints(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->translate(20, 30);
        // 20 mm = 56.692913 pt, 30 mm = 85.03937 pt
        self::assertStringContainsString('1 0 0 1 56.692913 85.03937 cm', $this->content($page));
    }

    public function testFontSizeStaysInPoints(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->text(10, 10, 'Hi');
        // /F1 12 Tf -- the 12 pt font size is NOT converted.
        self::assertStringContainsString('/F1 12 Tf', $this->content($page));
    }

    public function testStringWidthReturnsUserUnit(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->setFont(Font::helvetica(), 12);
        $widthMm = $page->stringWidth('A');
        // Helvetica 'A' at 12pt is roughly 8.34 pt -> 2.94 mm. Sanity check.
        self::assertGreaterThan(2.0, $widthMm);
        self::assertLessThan(4.0, $widthMm);
    }

    public function testCellResultRoundTripsThroughUserUnit(): void
    {
        $page = (new Document(Unit::MM))->addPage();
        $page->setFont(Font::helvetica(), 12);
        $result = $page->cell(x: 10, y: 20, w: 50, h: 15, text: 'x');
        // y was 20 mm and h was 15 mm: bottom y = 35 mm
        self::assertEqualsWithDelta(35.0, $result->y, 1e-6);
        self::assertEqualsWithDelta(15.0, $result->height, 1e-6);
    }

    public function testImageDimensionsAreConverted(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $img = \DragonOfMercy\PhpPdf\Image::fromBytes(
            \DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory::pngRgb(width: 10, height: 5),
        );
        $page->image($img, x: 10, y: 20, w: 50, h: 25);
        // 50 mm = 141.732283 pt, 25 mm = 70.866142 pt, x=10mm=28.346457, y+h = 45mm = 127.559055
        self::assertStringContainsString(
            '141.732283 0 0 -70.866142 28.346457 127.559055 cm',
            $page->contentStream()->bytes(),
        );
    }
}
