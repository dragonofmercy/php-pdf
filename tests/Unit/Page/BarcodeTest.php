<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Barcode\Barcode;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
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
}
