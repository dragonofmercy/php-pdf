<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DOMDocument;
use DOMElement;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\GradientResolver;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\RadialGradient;
use DragonOfMercy\PhpPdf\Svg\SpreadMethod;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class GradientResolverTest extends TestCase
{
    /** @return array<string, DOMElement> */
    private function defsFrom(string $svg): array
    {
        $doc = new DOMDocument();
        $doc->loadXML($svg);
        $map = [];
        foreach (['linearGradient', 'radialGradient'] as $tag) {
            foreach ($doc->getElementsByTagNameNS('http://www.w3.org/2000/svg', $tag) as $el) {
                if ($el->hasAttribute('id')) {
                    $map[$el->getAttribute('id')] = $el;
                }
            }
        }
        return $map;
    }

    public function testResolvesSimpleLinear(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertInstanceOf(LinearGradient::class, $g);
        self::assertSame(GradientUnits::OBJECT_BOUNDING_BOX, $g->units());
        self::assertCount(2, $g->stops());
        self::assertEqualsWithDelta(1.0, $g->stops()[1]->color->b, 1e-9);
    }

    public function testUnknownIdReturnsNull(): void
    {
        self::assertNull((new GradientResolver([]))->resolve('nope', SvgColor::black()));
    }

    public function testHrefInheritsStops(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><linearGradient id="base"><stop offset="0" stop-color="#000000"/><stop offset="1" stop-color="#ffffff"/></linearGradient><linearGradient id="g" xlink:href="#base" x1="0" y1="0" x2="0" y2="1"/></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertInstanceOf(LinearGradient::class, $g);
        self::assertCount(2, $g->stops());
        self::assertSame(1.0, $g->y2);
    }

    public function testHrefCycleThrows(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><linearGradient id="a" xlink:href="#b"/><linearGradient id="b" xlink:href="#a"/></svg>');
        $this->expectException(PdfException::class);
        (new GradientResolver($defs))->resolve('a', SvgColor::black());
    }

    public function testPercentOffsetAndMonotonicClamp(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g"><stop offset="50%" stop-color="#000000"/><stop offset="10%" stop-color="#ffffff"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        $offsets = array_map(static fn ($s) => $s->offset, $g->stops());
        self::assertSame([0.0, 0.5, 0.5, 1.0], $offsets);
    }

    public function testSingleStopKept(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g"><stop offset="0.4" stop-color="#abcdef"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame([0.0, 1.0], array_map(static fn ($s) => $s->offset, $g->stops()));
    }

    public function testZeroStopsReturnsNull(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g"/></svg>');
        self::assertNull((new GradientResolver($defs))->resolve('g', SvgColor::black()));
    }

    public function testUniformOpacityDetected(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g"><stop offset="0" stop-color="#000" stop-opacity="0.5"/><stop offset="1" stop-color="#fff" stop-opacity="0.5"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame(0.5, $g->uniformOpacity());
    }

    public function testMixedOpacityFallsBackToOne(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g"><stop offset="0" stop-color="#000" stop-opacity="0.2"/><stop offset="1" stop-color="#fff" stop-opacity="0.9"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame(1.0, $g->uniformOpacity());
    }

    public function testRadialDefaultsAndFocal(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><radialGradient id="g" cx="0.5" cy="0.5" r="0.5" fx="0.2" fy="0.3"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></radialGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertInstanceOf(RadialGradient::class, $g);
        self::assertSame(0.2, $g->fx);
        self::assertSame(0.3, $g->fy);
    }

    public function testSpreadMethodDefaultsToPad(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame(SpreadMethod::PAD, $g->spreadMethod());
    }

    public function testSpreadMethodReflectParsed(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g" spreadMethod="reflect" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame(SpreadMethod::REFLECT, $g->spreadMethod());
    }

    public function testSpreadMethodRepeatParsedOnRadial(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><radialGradient id="g" spreadMethod="repeat" cx="0.5" cy="0.5" r="0.25"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></radialGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame(SpreadMethod::REPEAT, $g->spreadMethod());
    }

    public function testSpreadMethodUnknownFallsBackToPad(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g" spreadMethod="rebound" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame(SpreadMethod::PAD, $g->spreadMethod());
    }

    public function testSpreadMethodInheritedViaHref(): void
    {
        $defs = $this->defsFrom('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><linearGradient id="base" spreadMethod="repeat"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient><linearGradient id="g" xlink:href="#base" x1="0" y1="0" x2="1" y2="0"/></svg>');
        $g = (new GradientResolver($defs))->resolve('g', SvgColor::black());
        self::assertNotNull($g);
        self::assertSame(SpreadMethod::REPEAT, $g->spreadMethod());
    }
}
