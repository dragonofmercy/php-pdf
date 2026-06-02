<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\ShapeFlattener;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgPolygon;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class ShapeFlattenerTest extends TestCase
{
    public function testRectFlattensToOneRingScaledByDeviceMatrix(): void
    {
        $rect = new SvgRect(null, SvgPaint::default(), 5.0, 5.0, 10.0, 20.0, 0.0, 0.0);
        $rings = ShapeFlattener::toRings($rect, SvgMatrix::scale(2.0, 2.0));
        self::assertCount(1, $rings);
        // 4 corners (a closed axis-aligned rect ring); device coords = user * 2.
        // The ring should contain points near (10,10), (30,10), (30,50), (10,50) in some order.
        $bounds = $this->bounds($rings[0]);
        self::assertEqualsWithDelta(10.0, $bounds['minX'], 1e-6);
        self::assertEqualsWithDelta(30.0, $bounds['maxX'], 1e-6);
        self::assertEqualsWithDelta(10.0, $bounds['minY'], 1e-6);
        self::assertEqualsWithDelta(50.0, $bounds['maxY'], 1e-6);
    }

    public function testPolygonRingClosed(): void
    {
        $poly = new SvgPolygon(null, SvgPaint::default(), [[0.0, 0.0], [10.0, 0.0], [5.0, 10.0]]);
        $rings = ShapeFlattener::toRings($poly, SvgMatrix::identity());
        self::assertCount(1, $rings);
        self::assertGreaterThanOrEqual(3, count($rings[0]));
    }

    public function testPolylineFlattensToOneRing(): void
    {
        $line = new SvgPolyline(null, SvgPaint::default(), [[0.0, 0.0], [10.0, 0.0], [5.0, 10.0]]);
        $rings = ShapeFlattener::toRings($line, SvgMatrix::identity());
        self::assertCount(1, $rings);
        self::assertSame(3, count($rings[0]));
    }

    public function testCircleFlattensToOneRingWithinBounds(): void
    {
        $circle = new SvgCircle(null, SvgPaint::default(), 10.0, 10.0, 5.0);
        $rings = ShapeFlattener::toRings($circle, SvgMatrix::identity());
        self::assertCount(1, $rings);
        $bounds = $this->bounds($rings[0]);
        self::assertEqualsWithDelta(5.0, $bounds['minX'], 1e-6);
        self::assertEqualsWithDelta(15.0, $bounds['maxX'], 1e-6);
        self::assertEqualsWithDelta(5.0, $bounds['minY'], 1e-6);
        self::assertEqualsWithDelta(15.0, $bounds['maxY'], 1e-6);
        self::assertGreaterThan(8, count($rings[0]));
    }

    /**
     * @param list<array{x: float, y: float}> $ring
     * @return array{minX: float, maxX: float, minY: float, maxY: float}
     */
    private function bounds(array $ring): array
    {
        self::assertNotEmpty($ring);
        $minX = $maxX = $ring[0]['x'];
        $minY = $maxY = $ring[0]['y'];
        foreach ($ring as $p) {
            $minX = min($minX, $p['x']);
            $maxX = max($maxX, $p['x']);
            $minY = min($minY, $p['y']);
            $maxY = max($maxY, $p['y']);
        }

        return ['minX' => $minX, 'maxX' => $maxX, 'minY' => $minY, 'maxY' => $maxY];
    }
}
