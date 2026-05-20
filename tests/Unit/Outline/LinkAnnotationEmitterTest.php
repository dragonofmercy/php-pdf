<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotationEmitter;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class LinkAnnotationEmitterTest extends TestCase
{
    private function emitter(Unit $unit = Unit::PT): LinkAnnotationEmitter
    {
        return new LinkAnnotationEmitter($unit);
    }

    public function testUriAnnotationEmitsCorrectDictWithFlippedRect(): void
    {
        $a = new LinkAnnotation(x: 50.0, y: 100.0, width: 200.0, height: 12.0, link: Link::url('https://example.com'));
        $obj = $this->emitter()->emit($a, 842.0, [], [], 42, 'page #1');
        $b = $obj->toBytes();
        self::assertStringStartsWith('42 0 obj', $b);
        self::assertStringContainsString('/Type /Annot', $b);
        self::assertStringContainsString('/Subtype /Link', $b);
        self::assertStringContainsString('/Border [0 0 0]', $b);
        self::assertStringContainsString('/Rect [50 730 250 742]', $b);
        self::assertStringContainsString('/A << /Type /Action /S /URI /URI (https://example.com) >>', $b);
    }

    public function testGoToAnnotationResolvesPageRefAndFlipsXyzTop(): void
    {
        $pageRefs = [PdfReference::to(3, 0), PdfReference::to(5, 0), PdfReference::to(7, 0)];
        $pageHeightsPt = [842.0, 842.0, 842.0];
        $a = new LinkAnnotation(
            x: 10.0,
            y: 20.0,
            width: 50.0,
            height: 12.0,
            link: Link::destination(Destination::xyz(2, left: 100.0, top: 200.0, zoom: 1.5)),
        );
        $obj = $this->emitter()->emit($a, 842.0, $pageRefs, $pageHeightsPt, 99, 'page #1');
        $b = $obj->toBytes();
        self::assertStringContainsString('/Rect [10 810 60 822]', $b);
        self::assertStringContainsString('/A << /Type /Action /S /GoTo /D [7 0 R /XYZ 100 642 1.5] >>', $b);
    }

    public function testGoToAnnotationFitDestinationEmitsBareFit(): void
    {
        $pageRefs = [PdfReference::to(3, 0)];
        $pageHeightsPt = [842.0];
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::destination(Destination::fit(0)),
        );
        $b = $this->emitter()->emit($a, 842.0, $pageRefs, $pageHeightsPt, 11, 'page #1')->toBytes();
        self::assertStringContainsString('/D [3 0 R /Fit]', $b);
    }

    public function testGoToAnnotationFitWidthDestinationEmitsFitH(): void
    {
        $pageRefs = [PdfReference::to(3, 0)];
        $pageHeightsPt = [842.0];
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::destination(Destination::fitWidth(0, top: 50.0)),
        );
        $b = $this->emitter()->emit($a, 842.0, $pageRefs, $pageHeightsPt, 11, 'page #1')->toBytes();
        self::assertStringContainsString('/D [3 0 R /FitH 792]', $b);
    }

    public function testUrlParenthesesAndBackslashAreEscaped(): void
    {
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 1.0,
            height: 1.0,
            link: Link::url('https://example.com/(parens)\\backslash'),
        );
        $b = $this->emitter()->emit($a, 842.0, [], [], 5, 'page #1')->toBytes();
        self::assertStringContainsString('/URI (https://example.com/\\(parens\\)\\\\backslash)', $b);
    }

    public function testOutOfBoundsPageIndexThrowsContextualException(): void
    {
        $pageRefs = [PdfReference::to(3, 0)];
        $pageHeightsPt = [842.0];
        $a = new LinkAnnotation(
            x: 0.0,
            y: 0.0,
            width: 10.0,
            height: 10.0,
            link: Link::destination(Destination::page(5)),
        );
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Destination references out-of-bounds page index 5 (document has 1 page(s)) for page #1');
        $this->emitter()->emit($a, 842.0, $pageRefs, $pageHeightsPt, 7, 'page #1');
    }

    public function testEmissionIsDeterministic(): void
    {
        $pageRefs = [PdfReference::to(3, 0), PdfReference::to(5, 0)];
        $pageHeightsPt = [842.0, 842.0];
        $a = new LinkAnnotation(
            x: 10.0,
            y: 20.0,
            width: 30.0,
            height: 12.0,
            link: Link::destination(Destination::page(1)),
        );
        $b1 = $this->emitter()->emit($a, 842.0, $pageRefs, $pageHeightsPt, 1, 'page #1')->toBytes();
        $b2 = $this->emitter()->emit($a, 842.0, $pageRefs, $pageHeightsPt, 1, 'page #1')->toBytes();
        self::assertSame($b1, $b2);
    }
}
