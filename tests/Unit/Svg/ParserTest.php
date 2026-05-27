<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testParsesViewBox(): void
    {
        $meta = Parser::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect width="10" height="10"/></svg>');
        self::assertSame(0.0, $meta->viewBox->x);
        self::assertSame(100.0, $meta->viewBox->width);
        self::assertSame(50.0, $meta->viewBox->height);
    }

    public function testDerivesViewBoxFromWidthHeight(): void
    {
        $meta = Parser::parse('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="40"><rect width="10" height="10"/></svg>');
        self::assertSame(80.0, $meta->viewBox->width);
        self::assertSame(40.0, $meta->viewBox->height);
    }

    public function testParsesRect(): void
    {
        $meta = Parser::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="1" y="2" width="3" height="4" fill="red"/></svg>');
        self::assertCount(1, $meta->root->children);
        $rect = $meta->root->children[0];
        self::assertInstanceOf(SvgRect::class, $rect);
        self::assertSame(1.0, $rect->x);
        self::assertSame(2.0, $rect->y);
        self::assertSame(3.0, $rect->width);
        self::assertSame(4.0, $rect->height);
        self::assertNotNull($rect->paint()->fill);
    }

    public function testParsesCircle(): void
    {
        $meta = Parser::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="3"/></svg>');
        self::assertCount(1, $meta->root->children);
        $c = $meta->root->children[0];
        self::assertInstanceOf(SvgCircle::class, $c);
        self::assertSame(5.0, $c->cx);
        self::assertSame(3.0, $c->r);
    }

    public function testParsesPath(): void
    {
        $meta = Parser::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M 0 0 L 10 10" stroke="black"/></svg>');
        $p = $meta->root->children[0];
        self::assertInstanceOf(SvgPath::class, $p);
        self::assertCount(2, $p->commands);
        self::assertInstanceOf(MoveTo::class, $p->commands[0]);
        self::assertInstanceOf(LineTo::class, $p->commands[1]);
    }

    public function testGroupWithTransformAndInheritedFill(): void
    {
        $meta = Parser::parse(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' .
            '<g fill="red" transform="translate(10, 20)">' .
            '<rect width="5" height="5"/>' .
            '</g>' .
            '</svg>',
        );
        self::assertCount(1, $meta->root->children);
        $g = $meta->root->children[0];
        self::assertInstanceOf(SvgGroup::class, $g);
        self::assertNotNull($g->transform);
        self::assertSame(10.0, $g->transform->e);
        self::assertCount(1, $g->children);
        $rect = $g->children[0];
        self::assertInstanceOf(SvgRect::class, $rect);
        self::assertNotNull($rect->paint()->fill);
    }

    public function testUseReferenceInlinesTarget(): void
    {
        $xml = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 100">' .
            '<defs><rect id="r1" width="5" height="5" fill="blue"/></defs>' .
            '<use href="#r1" x="10" y="20"/>' .
            '</svg>';
        $meta = Parser::parse($xml);
        // <use> becomes a group with translation containing the resolved subtree.
        self::assertCount(1, $meta->root->children);
        $use = $meta->root->children[0];
        self::assertInstanceOf(SvgGroup::class, $use);
        self::assertNotNull($use->transform);
        self::assertSame(10.0, $use->transform->e);
        self::assertSame(20.0, $use->transform->f);
        self::assertCount(1, $use->children);
        self::assertInstanceOf(SvgRect::class, $use->children[0]);
    }

    public function testSkipsUnsupportedElements(): void
    {
        $xml = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">' .
            '<text x="0" y="10">hello</text>' .
            '<linearGradient id="g"/>' .
            '<rect width="5" height="5"/>' .
            '</svg>';
        $meta = Parser::parse($xml);
        // <text> is now supported; <linearGradient> (non-whitelisted) is still skipped.
        self::assertCount(2, $meta->root->children);
        self::assertInstanceOf(SvgText::class, $meta->root->children[0]);
        self::assertInstanceOf(SvgRect::class, $meta->root->children[1]);
    }

    public function testThrowsOnMalformedXml(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/SVG parse error at line \d+/');
        Parser::parse('<svg><rect></svg>');
    }

    public function testThrowsOnNonSvgRoot(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Root element is not <svg> in the SVG namespace');
        Parser::parse('<?xml version="1.0"?><html><body/></html>');
    }

    public function testThrowsWhenNeitherViewBoxNorDimensionsPresent(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Cannot determine SVG intrinsic dimensions');
        Parser::parse('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
    }

    public function testThrowsOnInvalidViewBox(): void
    {
        $this->expectException(PdfException::class);
        Parser::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="bad value"><rect width="1" height="1"/></svg>');
    }

    public function testThrowsOnUseCycle(): void
    {
        $xml = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">' .
            '<defs>' .
            '<g id="a"><use href="#b"/></g>' .
            '<g id="b"><use href="#a"/></g>' .
            '</defs>' .
            '<use href="#a"/>' .
            '</svg>';
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/Cycle detected in <use>/');
        Parser::parse($xml);
    }

    public function testSilentSkipOnMissingUseTarget(): void
    {
        $xml = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">' .
            '<use href="#nonexistent"/>' .
            '<rect width="1" height="1"/>' .
            '</svg>';
        $meta = Parser::parse($xml);
        // <use> with missing target dropped; only the rect remains.
        self::assertCount(1, $meta->root->children);
        self::assertInstanceOf(SvgRect::class, $meta->root->children[0]);
    }

    public function testThrowsOnOversizedDocument(): void
    {
        $bigXml = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">' .
            str_repeat('<!-- pad -->', 500_000) .
            '<rect width="1" height="1"/></svg>';
        // 500_000 * 12 = 6 MB > 5 MiB limit.
        self::assertGreaterThan(5 * 1024 * 1024, strlen($bigXml));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/SVG document too large/');
        Parser::parse($bigXml);
    }

    public function testNamespacePrefixAccepted(): void
    {
        $xml = '<svg:svg xmlns:svg="http://www.w3.org/2000/svg" viewBox="0 0 10 10">' .
            '<svg:rect width="5" height="5"/>' .
            '</svg:svg>';
        $meta = Parser::parse($xml);
        self::assertCount(1, $meta->root->children);
    }
}
