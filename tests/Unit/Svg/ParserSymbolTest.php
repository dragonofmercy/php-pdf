<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use PHPUnit\Framework\TestCase;

final class ParserSymbolTest extends TestCase
{
    public function testSymbolRenderedViaUseProducesGroupWithViewBoxMatrix(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><symbol id="ico" viewBox="0 0 10 10"><rect width="10" height="10" fill="#f00"/></symbol></defs>'
            . '<use href="#ico" x="0" y="0" width="20" height="20"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        self::assertCount(1, $meta->root->children);
        $g = $meta->root->children[0];
        self::assertInstanceOf(SvgGroup::class, $g);
        self::assertNotNull($g->transform);
        self::assertEqualsWithDelta(2.0, $g->transform->a, 1e-9);
        self::assertEqualsWithDelta(2.0, $g->transform->d, 1e-9);
    }

    public function testSymbolNonSquareInSquareUseAppliesMeetCenterY(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50">'
            . '<defs><symbol id="tall" viewBox="0 0 10 20"><rect width="10" height="20"/></symbol></defs>'
            . '<use href="#tall" x="0" y="0" width="20" height="20"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $g = $meta->root->children[0];
        self::assertInstanceOf(SvgGroup::class, $g);
        self::assertNotNull($g->transform);
        self::assertEqualsWithDelta(1.0, $g->transform->a, 1e-9);
        self::assertEqualsWithDelta(1.0, $g->transform->d, 1e-9);
        self::assertEqualsWithDelta(5.0, $g->transform->e, 1e-9);
        self::assertEqualsWithDelta(0.0, $g->transform->f, 1e-9);
    }

    public function testSymbolWithoutViewBoxAppliesUseTranslateOnly(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50">'
            . '<defs><symbol id="raw"><rect width="5" height="5"/></symbol></defs>'
            . '<use href="#raw" x="10" y="20"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $g = $meta->root->children[0];
        self::assertInstanceOf(SvgGroup::class, $g);
        self::assertNotNull($g->transform);
        self::assertEqualsWithDelta(1.0, $g->transform->a, 1e-9);
        self::assertEqualsWithDelta(1.0, $g->transform->d, 1e-9);
        self::assertEqualsWithDelta(10.0, $g->transform->e, 1e-9);
        self::assertEqualsWithDelta(20.0, $g->transform->f, 1e-9);
    }
}
