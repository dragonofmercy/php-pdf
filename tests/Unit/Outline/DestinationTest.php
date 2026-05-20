<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\DestinationFit;
use PHPUnit\Framework\TestCase;

final class DestinationTest extends TestCase
{
    public function testPageNamedConstructorIsXyzTopLeftNoZoom(): void
    {
        $d = Destination::page(2);
        self::assertSame(2, $d->pageIndex);
        self::assertSame(DestinationFit::Xyz, $d->fit);
        self::assertSame(0.0, $d->left);
        self::assertSame(0.0, $d->top);
        self::assertNull($d->zoom);
    }

    public function testXyzNamedConstructorPropagatesCoordsAndZoom(): void
    {
        $d = Destination::xyz(0, left: 50.0, top: 80.0, zoom: 1.5);
        self::assertSame(0, $d->pageIndex);
        self::assertSame(DestinationFit::Xyz, $d->fit);
        self::assertSame(50.0, $d->left);
        self::assertSame(80.0, $d->top);
        self::assertSame(1.5, $d->zoom);
    }

    public function testXyzAllowsNullCoordsForKeepCurrent(): void
    {
        $d = Destination::xyz(3, left: null, top: null, zoom: null);
        self::assertNull($d->left);
        self::assertNull($d->top);
        self::assertNull($d->zoom);
    }

    public function testFitNamedConstructorHasNoCoords(): void
    {
        $d = Destination::fit(4);
        self::assertSame(4, $d->pageIndex);
        self::assertSame(DestinationFit::Fit, $d->fit);
        self::assertNull($d->left);
        self::assertNull($d->top);
        self::assertNull($d->zoom);
    }

    public function testFitWidthNamedConstructorCarriesTopOnly(): void
    {
        $d = Destination::fitWidth(1, top: 25.0);
        self::assertSame(1, $d->pageIndex);
        self::assertSame(DestinationFit::FitH, $d->fit);
        self::assertNull($d->left);
        self::assertSame(25.0, $d->top);
        self::assertNull($d->zoom);
    }

    public function testRejectsNegativePageIndex(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Destination pageIndex must be non-negative, got -1');
        Destination::page(-1);
    }
}
