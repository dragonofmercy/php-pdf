<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Marker;

use DOMDocument;
use DOMElement;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerOrientMode;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerResolver;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerUnits;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class MarkerResolverTest extends TestCase
{
    /** @return array<string, DOMElement> */
    private function defsFrom(string $svg): array
    {
        $doc = new DOMDocument();
        $doc->loadXML($svg);
        $map = [];
        foreach ($doc->getElementsByTagNameNS('http://www.w3.org/2000/svg', 'marker') as $el) {
            if ($el->hasAttribute('id')) {
                $map[$el->getAttribute('id')] = $el;
            }
        }
        return $map;
    }

    private function resolver(string $svg): MarkerResolver
    {
        return new MarkerResolver($this->defsFrom($svg), new Parser());
    }

    public function testResolvesSimpleMarker(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><marker id="m" markerWidth="10" markerHeight="10" refX="5" refY="5"><path d="M0 0 L10 5 L0 10 Z" fill="#f00"/></marker></svg>');
        $m = $r->resolve('m', SvgColor::black());
        self::assertNotNull($m);
        self::assertSame(10.0, $m->markerWidth);
        self::assertSame(10.0, $m->markerHeight);
        self::assertSame(5.0, $m->refX);
        self::assertSame(5.0, $m->refY);
        self::assertCount(1, $m->nodes);
    }

    public function testDefaultsPerSpec(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><marker id="m"><path d="M0 0 L3 3" fill="#000"/></marker></svg>');
        $m = $r->resolve('m', SvgColor::black());
        self::assertNotNull($m);
        self::assertSame(3.0, $m->markerWidth);
        self::assertSame(3.0, $m->markerHeight);
        self::assertSame(0.0, $m->refX);
        self::assertSame(0.0, $m->refY);
        self::assertSame(MarkerUnits::STROKE_WIDTH, $m->units);
        self::assertSame(MarkerOrientMode::NUMBER, $m->orient->mode);
        self::assertSame(0.0, $m->orient->angleDeg);
    }

    public function testOrientAuto(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><marker id="m" orient="auto"><path d="M0 0 L1 0"/></marker></svg>');
        $m = $r->resolve('m', SvgColor::black());
        self::assertNotNull($m);
        self::assertSame(MarkerOrientMode::AUTO, $m->orient->mode);
    }

    public function testUnknownIdReturnsNull(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"/>');
        self::assertNull($r->resolve('nope', SvgColor::black()));
    }

    public function testEmptyChildrenReturnsNull(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><marker id="m"/></svg>');
        self::assertNull($r->resolve('m', SvgColor::black()));
    }

    public function testTextChildIsStripped(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><marker id="m"><text>x</text><rect width="1" height="1"/></marker></svg>');
        $m = $r->resolve('m', SvgColor::black());
        self::assertNotNull($m);
        self::assertCount(1, $m->nodes);
    }
}
