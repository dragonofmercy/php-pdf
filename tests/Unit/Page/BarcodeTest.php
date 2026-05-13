<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Barcode\Barcode;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class BarcodeTest extends TestCase
{
    public function testBarcodeDelegatesToDrawAndReturnsSelf(): void
    {
        $stub = new class implements Barcode {
            public ?Page $seenPage = null;
            public ?float $seenX = null;
            public ?float $seenY = null;
            public ?float $seenW = null;
            public ?float $seenH = null;
            public function withColor(Color $color): self { return $this; }
            public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
            {
                $this->seenPage = $page;
                $this->seenX = $x;
                $this->seenY = $y;
                $this->seenW = $w;
                $this->seenH = $h;
            }
        };

        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $returned = $page->barcode($stub, x: 10.0, y: 20.0, w: 30.0, h: 12.0);

        self::assertSame($page, $returned);
        self::assertSame($page, $stub->seenPage);
        self::assertSame(10.0, $stub->seenX);
        self::assertSame(20.0, $stub->seenY);
        self::assertSame(30.0, $stub->seenW);
        self::assertSame(12.0, $stub->seenH);
    }

    public function testBarcodePassesNullHWhenOmitted(): void
    {
        $stub = new class implements Barcode {
            public mixed $seenH = 'unset';
            public function withColor(Color $color): self { return $this; }
            public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
            {
                $this->seenH = $h;
            }
        };

        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode($stub, x: 10.0, y: 20.0, w: 30.0);

        self::assertNull($stub->seenH);
    }

    public function testBarcodeFallsBackToCursorWhenXYOmitted(): void
    {
        $stub = new class implements Barcode {
            public ?float $seenX = null;
            public ?float $seenY = null;
            public function withColor(Color $color): self { return $this; }
            public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
            {
                $this->seenX = $x;
                $this->seenY = $y;
            }
        };

        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setXY(42.0, 84.0);
        $page->barcode($stub, w: 30.0, h: 12.0);

        self::assertSame(42.0, $stub->seenX);
        self::assertSame(84.0, $stub->seenY);
    }

    public function testBarcodeAdvancesCursorXByWidth(): void
    {
        $stub = new class implements Barcode {
            public function withColor(Color $color): self { return $this; }
            public function draw(Page $page, float $x, float $y, float $w, ?float $h): void {}
        };

        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setXY(10.0, 50.0);
        $page->barcode($stub, w: 30.0, h: 12.0);

        // x advances by w (10 + 30), y untouched.
        self::assertSame(40.0, $page->getX());
        self::assertSame(50.0, $page->getY());
    }

    public function testBarcodeThrowsWhenWidthOmitted(): void
    {
        $stub = new class implements Barcode {
            public function withColor(Color $color): self { return $this; }
            public function draw(Page $page, float $x, float $y, float $w, ?float $h): void {}
        };

        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Barcode width is required');
        $page->barcode($stub, x: 10.0, y: 20.0);
    }

    public function testBarcodeThrowsWhenCursorUnsetAndXOmitted(): void
    {
        $stub = new class implements Barcode {
            public function withColor(Color $color): self { return $this; }
            public function draw(Page $page, float $x, float $y, float $w, ?float $h): void {}
        };

        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('no cursor set');
        $page->barcode($stub, y: 20.0, w: 30.0);
    }

    public function testBarcodeThrowsWhenCursorUnsetAndYOmitted(): void
    {
        $stub = new class implements Barcode {
            public function withColor(Color $color): self { return $this; }
            public function draw(Page $page, float $x, float $y, float $w, ?float $h): void {}
        };

        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('no cursor set');
        $page->barcode($stub, x: 10.0, w: 30.0);
    }
}
