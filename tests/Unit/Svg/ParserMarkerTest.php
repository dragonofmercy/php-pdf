<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Marker\MarkerSet;
use DragonOfMercy\PhpPdf\Svg\Marker\SvgMarker;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgLine;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use PHPUnit\Framework\TestCase;

final class ParserMarkerTest extends TestCase
{
    public function testMarkerStartAttachedViaStyleResolver(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><marker id="arrow"><rect width="3" height="3" fill="#000"/></marker></defs>'
            . '<line x1="0" y1="0" x2="100" y2="0" stroke="#000" marker-start="url(#arrow)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $line = $meta->root->children[0];
        self::assertInstanceOf(SvgLine::class, $line);
        $markers = $line->paint()->markers;
        self::assertInstanceOf(MarkerSet::class, $markers);
        self::assertInstanceOf(SvgMarker::class, $markers->start);
        self::assertNull($markers->mid);
        self::assertNull($markers->end);
    }

    public function testMarkerShorthandSetsAllThree(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><marker id="arrow"><rect width="3" height="3"/></marker></defs>'
            . '<line x1="0" y1="0" x2="100" y2="0" stroke="#000" marker="url(#arrow)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $line = $meta->root->children[0];
        self::assertInstanceOf(SvgLine::class, $line);
        $markers = $line->paint()->markers;
        self::assertInstanceOf(MarkerSet::class, $markers);
        self::assertNotNull($markers->start);
        self::assertNotNull($markers->mid);
        self::assertNotNull($markers->end);
    }

    public function testMarkerEndOnly(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><marker id="arrow"><rect width="3" height="3"/></marker></defs>'
            . '<polyline points="0,0 50,50 100,0" stroke="#000" fill="none" marker-end="url(#arrow)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $poly = $meta->root->children[0];
        self::assertInstanceOf(SvgPolyline::class, $poly);
        $markers = $poly->paint()->markers;
        self::assertInstanceOf(MarkerSet::class, $markers);
        self::assertNull($markers->start);
        self::assertNull($markers->mid);
        self::assertNotNull($markers->end);
    }

    public function testNoMarkerLeavesPaintMarkersNull(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><line x1="0" y1="0" x2="100" y2="0" stroke="#000"/></svg>';
        $meta = Parser::parse($svg);
        $line = $meta->root->children[0];
        self::assertInstanceOf(SvgLine::class, $line);
        self::assertNull($line->paint()->markers);
    }
}
