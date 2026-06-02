<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\ColorMatrixType;
use DragonOfMercy\PhpPdf\Svg\Filter\CompositeOperator;
use DragonOfMercy\PhpPdf\Svg\Filter\FeColorMatrix;
use DragonOfMercy\PhpPdf\Svg\Filter\FeComposite;
use DragonOfMercy\PhpPdf\Svg\Filter\FeGaussianBlur;
use DragonOfMercy\PhpPdf\Svg\Filter\FeMerge;
use DragonOfMercy\PhpPdf\Svg\Filter\FilterPrimitive;
use DragonOfMercy\PhpPdf\Svg\Filter\FilterUnits;
use DragonOfMercy\PhpPdf\Svg\Filter\Subregion;
use DragonOfMercy\PhpPdf\Svg\Filter\SvgFilter;
use DragonOfMercy\PhpPdf\Svg\SvgFiltered;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use PHPUnit\Framework\TestCase;

final class FilterValueObjectsTest extends TestCase
{
    public function testPrimitivesImplementInterface(): void
    {
        $blur = new FeGaussianBlur(in: null, result: 'b', stdDeviationX: 2.0, stdDeviationY: 3.0, subregion: null);
        self::assertInstanceOf(FilterPrimitive::class, $blur);
        self::assertSame(2.0, $blur->stdDeviationX);
        self::assertSame('b', $blur->result);
    }

    public function testColorMatrixHoldsValues(): void
    {
        $cm = new FeColorMatrix(in: 'b', result: null, type: ColorMatrixType::SATURATE, values: [0.5], subregion: null);
        self::assertSame(ColorMatrixType::SATURATE, $cm->type);
        self::assertSame([0.5], $cm->values);
    }

    public function testCompositeArithmeticCoefficients(): void
    {
        $c = new FeComposite(in: 'a', in2: 'b', result: null, operator: CompositeOperator::ARITHMETIC, k1: 0.0, k2: 1.0, k3: 1.0, k4: 0.0, subregion: null);
        self::assertSame(CompositeOperator::ARITHMETIC, $c->operator);
        self::assertSame(1.0, $c->k2);
    }

    public function testMergeInputs(): void
    {
        $m = new FeMerge(result: null, inputs: ['shadow', null], subregion: null);
        self::assertSame(['shadow', null], $m->inputs);
    }

    public function testSvgFilterHoldsPrimitives(): void
    {
        $f = new SvgFilter(
            id: 'f1',
            filterUnits: FilterUnits::OBJECT_BOUNDING_BOX,
            primitiveUnits: FilterUnits::USER_SPACE_ON_USE,
            x: -0.1, y: -0.1, width: 1.2, height: 1.2,
            primitives: [new FeGaussianBlur(null, null, 1.0, 1.0, null)],
        );
        self::assertCount(1, $f->primitives);
        self::assertSame('f1', $f->id);
    }

    public function testSvgFilteredWrapsChild(): void
    {
        $child = new SvgGroup(null, []);
        $filter = new SvgFilter('f', FilterUnits::OBJECT_BOUNDING_BOX, FilterUnits::USER_SPACE_ON_USE, -0.1, -0.1, 1.2, 1.2, []);
        $wrapped = new SvgFiltered($filter, $child);
        self::assertInstanceOf(SvgNode::class, $wrapped);
        self::assertSame($child, $wrapped->child);
        self::assertSame($filter, $wrapped->filter);
    }

    public function testSubregionOptionalFields(): void
    {
        $s = new Subregion(x: 10.0, y: null, width: 50.0, height: null);
        self::assertSame(10.0, $s->x);
        self::assertNull($s->y);
    }
}
