<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\FeGaussianBlur;
use DragonOfMercy\PhpPdf\Svg\Filter\FeOffset;
use DragonOfMercy\PhpPdf\Svg\Filter\FilterUnits;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgFiltered;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use PHPUnit\Framework\TestCase;

final class FilterParseTest extends TestCase
{
    /** @return list<SvgFiltered> */
    private function findFiltered(SvgNode $node): array
    {
        $found = [];
        if ($node instanceof SvgFiltered) {
            $found[] = $node;
            return $found;
        }
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $c) {
                $found = array_merge($found, $this->findFiltered($c));
            }
        }
        return $found;
    }

    public function testParsesFilterAndWrapsElement(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
          <filter id="f" x="-20%" y="-20%" width="140%" height="140%">
            <feOffset in="SourceAlpha" dx="2" dy="3" result="o"/>
            <feGaussianBlur in="o" stdDeviation="2 3" result="b"/>
            <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
          </filter>
          <rect x="10" y="10" width="50" height="50" fill="red" filter="url(#f)"/>
        </svg>
        SVG;
        $meta = Parser::parse($svg);
        $filtered = $this->findFiltered($meta->root);
        self::assertCount(1, $filtered);
        $f = $filtered[0]->filter;
        self::assertSame(FilterUnits::USER_SPACE_ON_USE, $f->primitiveUnits);
        self::assertInstanceOf(FeOffset::class, $f->primitives[0]);
        self::assertSame(2.0, $f->primitives[0]->dx);
        self::assertInstanceOf(FeGaussianBlur::class, $f->primitives[1]);
        self::assertSame(2.0, $f->primitives[1]->stdDeviationX);
        self::assertSame(3.0, $f->primitives[1]->stdDeviationY);
    }

    public function testUnknownFilterRefRendersWithoutFilter(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" filter="url(#missing)"/></svg>';
        $meta = Parser::parse($svg);
        self::assertCount(0, $this->findFiltered($meta->root));
    }
}
