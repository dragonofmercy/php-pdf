<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\Color;
use PhpPdf\LineCap;
use PhpPdf\LineJoin;
use PhpPdf\Page;
use PhpPdf\Path;
use PhpPdf\PathOperation;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    private function content(Page $page): string
    {
        $bytes = $page->contentStream()->bytes();
        if ($bytes === '') {
            return '';
        }
        $prefix = "1 0 0 -1 0 841.89 cm\n";
        self::assertStringStartsWith($prefix, $bytes);
        return substr($bytes, strlen($prefix));
    }

    private function page(): Page
    {
        return new Page(pageWidth: 595.28, pageHeight: 841.89);
    }

    public function testLineAppendsMoveAndLineThenStrokes(): void
    {
        $page = $this->page();
        $op = $page->line(10, 20, 30, 40);
        self::assertInstanceOf(PathOperation::class, $op);
        $op->stroke();
        self::assertSame("10 20 m\n30 40 l\nS\n", $this->content($page));
    }

    public function testRectAppendsReThenFills(): void
    {
        $page = $this->page();
        $page->rect(5, 10, 100, 50)->fill();
        self::assertSame("5 10 100 50 re\nf\n", $this->content($page));
    }

    public function testCircleUsesFourCubicBeziers(): void
    {
        $page = $this->page();
        $page->circle(100, 100, 50)->stroke();
        $content = $this->content($page);
        self::assertSame(1, substr_count($content, " m\n"));
        self::assertSame(4, substr_count($content, " c\n"));
        self::assertStringContainsString("h\n", $content);
        self::assertStringEndsWith("S\n", $content);
    }

    public function testPathBuilderReturnsPath(): void
    {
        $page = $this->page();
        $path = $page->path();
        self::assertInstanceOf(Path::class, $path);
    }

    public function testSetStrokeColorEmitsRG(): void
    {
        $page = $this->page();
        $page->setStrokeColor(Color::rgb(255, 0, 0));
        self::assertSame("1 0 0 RG\n", $this->content($page));
    }

    public function testSetFillColorEmitsRg(): void
    {
        $page = $this->page();
        $page->setFillColor(Color::hex('#00ff00'));
        self::assertSame("0 1 0 rg\n", $this->content($page));
    }

    public function testSetLineWidth(): void
    {
        $page = $this->page();
        $page->setLineWidth(2.5);
        self::assertSame("2.5 w\n", $this->content($page));
    }

    public function testSetLineCapAndJoin(): void
    {
        $page = $this->page();
        $page->setLineCap(LineCap::ROUND);
        $page->setLineJoin(LineJoin::BEVEL);
        self::assertSame("1 J\n2 j\n", $this->content($page));
    }

    public function testSetDashPattern(): void
    {
        $page = $this->page();
        $page->setDashPattern([3.0, 2.0], 0.5);
        self::assertSame("[3 2] 0.5 d\n", $this->content($page));
    }

    public function testTranslateRotateScale(): void
    {
        $page = $this->page();
        $page->translate(10, 20);
        $page->scale(2, 3);
        $page->rotate(90);
        self::assertSame(
            "1 0 0 1 10 20 cm\n2 0 0 3 0 0 cm\n0 1 -1 0 0 0 cm\n",
            $this->content($page),
        );
    }

    public function testSaveAndRestore(): void
    {
        $page = $this->page();
        $page->save();
        $page->rect(0, 0, 10, 10)->fill();
        $page->restore();
        self::assertSame("q\n0 0 10 10 re\nf\nQ\n", $this->content($page));
    }

    public function testStateSettersAreChainable(): void
    {
        $page = $this->page();
        $result = $page
            ->setStrokeColor(Color::rgb(255, 0, 0))
            ->setLineWidth(1)
            ->save()
            ->translate(10, 10)
            ->restore();
        self::assertSame($page, $result);
    }
}
