<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\BlendMode;
use DragonOfMercy\PhpPdf\Svg\Filter\ColorInterpolation;
use DragonOfMercy\PhpPdf\Svg\Filter\ColorMatrixType;
use DragonOfMercy\PhpPdf\Svg\Filter\CompositeOperator;
use DragonOfMercy\PhpPdf\Svg\Filter\FilterUnits;
use PHPUnit\Framework\TestCase;

final class FilterEnumsTest extends TestCase
{
    public function testFilterUnitsFromString(): void
    {
        self::assertSame(FilterUnits::OBJECT_BOUNDING_BOX, FilterUnits::fromString('objectBoundingBox'));
        self::assertSame(FilterUnits::USER_SPACE_ON_USE, FilterUnits::fromString('userSpaceOnUse'));
        self::assertSame(FilterUnits::OBJECT_BOUNDING_BOX, FilterUnits::fromString('garbage', FilterUnits::OBJECT_BOUNDING_BOX));
    }

    public function testBlendModeFromString(): void
    {
        self::assertSame(BlendMode::MULTIPLY, BlendMode::fromString('multiply'));
        self::assertSame(BlendMode::NORMAL, BlendMode::fromString('unknown'));
    }

    public function testCompositeOperatorFromString(): void
    {
        self::assertSame(CompositeOperator::ARITHMETIC, CompositeOperator::fromString('arithmetic'));
        self::assertSame(CompositeOperator::OVER, CompositeOperator::fromString('bogus'));
    }

    public function testColorMatrixTypeFromString(): void
    {
        self::assertSame(ColorMatrixType::SATURATE, ColorMatrixType::fromString('saturate'));
        self::assertSame(ColorMatrixType::MATRIX, ColorMatrixType::fromString(''));
    }

    public function testColorInterpolationFromString(): void
    {
        self::assertSame(ColorInterpolation::SRGB, ColorInterpolation::fromString('sRGB'));
        self::assertSame(ColorInterpolation::LINEAR_RGB, ColorInterpolation::fromString('linearRGB'));
        self::assertSame(ColorInterpolation::LINEAR_RGB, ColorInterpolation::fromString('auto'));
    }
}
