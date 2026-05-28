<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DOMDocument;
use DOMElement;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\PatternResolver;
use DragonOfMercy\PhpPdf\Svg\PatternUnits;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class PatternResolverTest extends TestCase
{
    /** @return array<string, DOMElement> */
    private function defsFrom(string $svg): array
    {
        $doc = new DOMDocument();
        $doc->loadXML($svg);
        $map = [];
        foreach ($doc->getElementsByTagNameNS('http://www.w3.org/2000/svg', 'pattern') as $el) {
            if ($el->hasAttribute('id')) {
                $map[$el->getAttribute('id')] = $el;
            }
        }
        return $map;
    }

    private function resolver(string $svg): PatternResolver
    {
        return new PatternResolver($this->defsFrom($svg), new Parser());
    }

    public function testResolvesSimplePattern(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><pattern id="p" patternUnits="userSpaceOnUse" x="0" y="0" width="10" height="10"><rect width="5" height="5" fill="#f00"/></pattern></svg>');
        $p = $r->resolve('p', SvgColor::black());
        self::assertNotNull($p);
        self::assertSame(PatternUnits::USER_SPACE_ON_USE, $p->units);
        self::assertSame(10.0, $p->width);
        self::assertCount(1, $p->nodes);
    }

    public function testUnknownIdReturnsNull(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"/>');
        self::assertNull($r->resolve('nope', SvgColor::black()));
    }

    public function testDefaultsObjectBoundingBoxWhenUnitsAbsent(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><pattern id="p" width="0.25" height="0.25"><rect width="1" height="1"/></pattern></svg>');
        $p = $r->resolve('p', SvgColor::black());
        self::assertNotNull($p);
        self::assertSame(PatternUnits::OBJECT_BOUNDING_BOX, $p->units);
    }

    public function testHrefInheritsChildrenAndAttributes(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><pattern id="base" patternUnits="userSpaceOnUse" width="20" height="20"><circle cx="10" cy="10" r="4"/></pattern><pattern id="derived" xlink:href="#base" width="30"/></svg>');
        $p = $r->resolve('derived', SvgColor::black());
        self::assertNotNull($p);
        self::assertSame(PatternUnits::USER_SPACE_ON_USE, $p->units);
        self::assertSame(30.0, $p->width); // child overrides
        self::assertSame(20.0, $p->height); // inherited from base
        self::assertCount(1, $p->nodes); // children inherited from base
    }

    public function testHrefCycleThrows(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><pattern id="a" xlink:href="#b"/><pattern id="b" xlink:href="#a"/></svg>');
        $this->expectException(PdfException::class);
        $r->resolve('a', SvgColor::black());
    }

    public function testZeroChildrenReturnsNull(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><pattern id="p" width="10" height="10"/></svg>');
        self::assertNull($r->resolve('p', SvgColor::black()));
    }

    public function testTextInPatternIsStripped(): void
    {
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><pattern id="p" patternUnits="userSpaceOnUse" width="20" height="20"><text x="0" y="10">hello</text><rect width="5" height="5"/></pattern></svg>');
        $p = $r->resolve('p', SvgColor::black());
        self::assertNotNull($p);
        // text stripped -> only the rect survives.
        self::assertCount(1, $p->nodes);
    }

    public function testImageInPatternIsStripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><pattern id="p" patternUnits="userSpaceOnUse" width="20" height="20"><image width="10" height="10" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII="/><rect width="5" height="5"/></pattern></svg>';
        $r = $this->resolver($svg);
        $p = $r->resolve('p', SvgColor::black());
        self::assertNotNull($p);
        // image stripped -> only the rect survives.
        self::assertCount(1, $p->nodes);
    }

    public function testPercentageCoordinatesParsed(): void
    {
        // Inkscape commonly emits objectBoundingBox patterns with percentage widths.
        $r = $this->resolver('<svg xmlns="http://www.w3.org/2000/svg"><pattern id="p" width="25%" height="50%"><rect width="1" height="1"/></pattern></svg>');
        $p = $r->resolve('p', SvgColor::black());
        self::assertNotNull($p);
        self::assertSame(0.25, $p->width);
        self::assertSame(0.5, $p->height);
    }

    public function testParserWiresPatternIntoFillResolution(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50">'
            . '<defs>'
            . '<pattern id="p" patternUnits="userSpaceOnUse" width="10" height="10"><rect width="5" height="5" fill="#f00"/></pattern>'
            . '</defs>'
            . '<rect width="50" height="50" fill="url(#p)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rootChildren = $meta->root->children;
        self::assertCount(1, $rootChildren);
        $rect = $rootChildren[0];
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgRect::class, $rect);
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgPattern::class, $rect->paint()->fill);
    }

    public function testParserWiresPatternIntoStrokeResolution(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50">'
            . '<defs>'
            . '<pattern id="p" patternUnits="userSpaceOnUse" width="10" height="10"><rect width="5" height="5" fill="#f00"/></pattern>'
            . '</defs>'
            . '<rect width="50" height="50" fill="none" stroke="url(#p)" stroke-width="2"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rect = $meta->root->children[0];
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgRect::class, $rect);
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgPattern::class, $rect->paint()->stroke);
    }

    public function testGradientStillResolvesWhenBothGradientAndPatternExist(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50">'
            . '<defs>'
            . '<linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient>'
            . '<pattern id="p" patternUnits="userSpaceOnUse" width="10" height="10"><rect width="5" height="5"/></pattern>'
            . '</defs>'
            . '<rect width="50" height="50" fill="url(#g)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rect = $meta->root->children[0];
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgRect::class, $rect);
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgGradient::class, $rect->paint()->fill);
    }

    public function testNestedUrlInsidePatternFallsBackToColor(): void
    {
        // A pattern child has fill="url(#g)" - inside a pattern, this should
        // NOT resolve to the gradient (would recurse / break tile rendering),
        // it falls back to default color (black per spec).
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50">'
            . '<defs>'
            . '<linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient>'
            . '<pattern id="p" patternUnits="userSpaceOnUse" width="10" height="10">'
            . '<rect width="5" height="5" fill="url(#g)"/>'
            . '</pattern>'
            . '</defs>'
            . '<rect width="50" height="50" fill="url(#p)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rect = $meta->root->children[0];
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgRect::class, $rect);
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgPattern::class, $rect->paint()->fill);
        $patternRect = $rect->paint()->fill->nodes[0];
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgRect::class, $patternRect);
        // The inner rect's fill must NOT be the gradient. The exact value
        // depends on the fallback path (inherited black, or SvgColor::black),
        // but it must NOT be an SvgGradient.
        self::assertNotInstanceOf(\DragonOfMercy\PhpPdf\Svg\SvgGradient::class, $patternRect->paint()->fill);
    }
}
