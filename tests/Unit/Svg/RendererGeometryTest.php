<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgLine;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgPolygon;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use PHPUnit\Framework\TestCase;

final class RendererGeometryTest extends TestCase
{
    public function testViewBoxToUnitIdentityForUnitBox(): void
    {
        $m = Renderer::viewBoxToUnitMatrix(new ViewBox(0.0, 0.0, 1.0, 1.0), PreserveAspectRatio::default());
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testViewBoxToUnitScalesUniformlyMeet(): void
    {
        // viewBox 0 0 100 50 -> unit square. meet -> uniform scale = min(1/100, 1/50) = 1/100.
        // After scaling: vw = 1, vh = 0.5. xMidYMid -> dx = 0, dy = (1 - 0.5)/2 = 0.25.
        $m = Renderer::viewBoxToUnitMatrix(new ViewBox(0.0, 0.0, 100.0, 50.0), PreserveAspectRatio::default());
        self::assertEqualsWithDelta(0.01, $m->a, 1e-9);
        self::assertEqualsWithDelta(0.01, $m->d, 1e-9);
        self::assertEqualsWithDelta(0.0, $m->e, 1e-9);
        self::assertEqualsWithDelta(0.25, $m->f, 1e-9);
    }

    public function testViewBoxToUnitSliceCovers(): void
    {
        // viewBox 0 0 100 50 -> slice -> uniform scale = max(1/100, 1/50) = 0.02.
        // After: vw = 2, vh = 1. xMidYMid -> dx = (1 - 2)/2 = -0.5, dy = 0.
        $m = Renderer::viewBoxToUnitMatrix(
            new ViewBox(0.0, 0.0, 100.0, 50.0),
            new PreserveAspectRatio(Align::X_MID_Y_MID, MeetOrSlice::SLICE),
        );
        self::assertEqualsWithDelta(0.02, $m->a, 1e-9);
        self::assertEqualsWithDelta(0.02, $m->d, 1e-9);
        self::assertEqualsWithDelta(-0.5, $m->e, 1e-9);
    }

    public function testViewBoxToUnitNoneStretches(): void
    {
        $m = Renderer::viewBoxToUnitMatrix(
            new ViewBox(0.0, 0.0, 100.0, 50.0),
            new PreserveAspectRatio(Align::NONE, MeetOrSlice::MEET),
        );
        self::assertEqualsWithDelta(0.01, $m->a, 1e-9);
        self::assertEqualsWithDelta(0.02, $m->d, 1e-9);
    }

    public function testViewBoxOffsetOrigin(): void
    {
        // viewBox -50 -25 100 50 with xMidYMid meet: scale = 0.01, dx = 0, dy = 0.25.
        // After scaling, the viewBox origin (-50, -25) maps to (-50*0.01, -25*0.01) = (-0.5, -0.25).
        // To put it at (0, 0.25) in the unit, translate by (0.5, 0.5).
        $m = Renderer::viewBoxToUnitMatrix(new ViewBox(-50.0, -25.0, 100.0, 50.0), PreserveAspectRatio::default());
        [$x0, $y0] = $m->apply(-50.0, -25.0);
        self::assertEqualsWithDelta(0.0, $x0, 1e-9);
        self::assertEqualsWithDelta(0.25, $y0, 1e-9);
    }

    public function testRenderEmptyDocumentProducesPrologueOnly(): void
    {
        $svg = new SvgMetadata(new ViewBox(0.0, 0.0, 1.0, 1.0), PreserveAspectRatio::default(), new SvgGroup(null, []));
        $bytes = (new Renderer())->render($svg)['bytes'];
        // Identity viewBox -> no cm needed. With no shapes, the stream is empty (or only whitespace).
        self::assertSame('', trim($bytes));
    }

    public function testRenderRectEmitsReOperator(): void
    {
        $svg = $this->makeSvg([new SvgRect(null, SvgPaint::default(), 1.0, 2.0, 3.0, 4.0, 0.0, 0.0)]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('1 2 3 4 re', $bytes);
        // q ... Q wrapping
        self::assertStringContainsString('q', $bytes);
        self::assertStringContainsString('Q', $bytes);
    }

    public function testRenderLineEmitsMoveLineStroke(): void
    {
        $line = new SvgLine(null, SvgPaint::default(), 0.0, 0.0, 10.0, 5.0);
        $svg = $this->makeSvg([$line]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('0 0 m', $bytes);
        self::assertStringContainsString('10 5 l', $bytes);
    }

    public function testRenderPolygonClosesPath(): void
    {
        $poly = new SvgPolygon(null, SvgPaint::default(), [[0.0, 0.0], [10.0, 0.0], [5.0, 10.0]]);
        $svg = $this->makeSvg([$poly]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('0 0 m', $bytes);
        self::assertStringContainsString('10 0 l', $bytes);
        self::assertStringContainsString('5 10 l', $bytes);
        self::assertStringContainsString('h', $bytes); // closepath operator
    }

    public function testRenderPolylineNoCloseOperator(): void
    {
        $poly = new SvgPolyline(null, SvgPaint::default(), [[0.0, 0.0], [10.0, 5.0]]);
        $svg = $this->makeSvg([$poly]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('0 0 m', $bytes);
        self::assertStringContainsString('10 5 l', $bytes);
        // No standalone " h " surrounded by whitespace.
        self::assertStringNotContainsString(' h ', "\n" . $bytes . "\n");
    }

    public function testRenderPathMoveAndLineAndClose(): void
    {
        $path = new SvgPath(null, SvgPaint::default(), [
            new MoveTo(0.0, 0.0),
            new LineTo(10.0, 0.0),
            new LineTo(10.0, 10.0),
            new ClosePath(),
        ]);
        $svg = $this->makeSvg([$path]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('0 0 m', $bytes);
        self::assertStringContainsString('10 0 l', $bytes);
        self::assertStringContainsString('10 10 l', $bytes);
        self::assertStringContainsString('h', $bytes);
    }

    public function testRenderCubicBezier(): void
    {
        $path = new SvgPath(null, SvgPaint::default(), [
            new MoveTo(0.0, 0.0),
            new CubicBezier(1.0, 0.0, 2.0, 1.0, 3.0, 1.0),
        ]);
        $svg = $this->makeSvg([$path]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('1 0 2 1 3 1 c', $bytes);
    }

    public function testRenderQuadraticBezierIsDegreeElevated(): void
    {
        // Q with current=(0,0), control=(3,3), end=(6,0) elevates to cubic with
        // C1 = (2/3)*(3,3) + (1/3)*(0,0) = (2, 2)
        // C2 = (2/3)*(3,3) + (1/3)*(6,0) = (4, 2)
        $path = new SvgPath(null, SvgPaint::default(), [
            new MoveTo(0.0, 0.0),
            new QuadraticBezier(3.0, 3.0, 6.0, 0.0),
        ]);
        $svg = $this->makeSvg([$path]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('2 2 4 2 6 0 c', $bytes);
    }

    public function testRenderArc(): void
    {
        $path = new SvgPath(null, SvgPaint::default(), [
            new MoveTo(1.0, 0.0),
            new Arc(1.0, 1.0, 0.0, false, true, 0.0, 1.0),
        ]);
        $svg = $this->makeSvg([$path]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        // At least one c operator emitted by arc approximation.
        self::assertStringContainsString(' c', $bytes);
    }

    public function testRenderRoundedRectExpandsToPath(): void
    {
        $rect = new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 2.0, 2.0);
        $svg = $this->makeSvg([$rect]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringNotContainsString(' re', $bytes); // not the rect shortcut
        self::assertStringContainsString(' c', $bytes); // at least one corner arc cubic
    }

    public function testRenderRoundedRectStartsWithSingleMoveTo(): void
    {
        $rect = new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 2.0, 2.0);
        $svg = $this->makeSvg([$rect]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertSame(1, substr_count($bytes, " m\n"));
    }

    public function testRenderFillEmitsRgAndF(): void
    {
        $rect = new SvgRect(null, SvgPaint::default()->withFill(new SvgColor(1.0, 0.0, 0.0)), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $svg = $this->makeSvg([$rect]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('1 0 0 rg', $bytes);
        self::assertStringContainsString("re\nf", $bytes);
    }

    public function testRenderStrokeEmitsRgWAndS(): void
    {
        $line = new SvgLine(null, SvgPaint::default()->withFillNone()->withStroke(new SvgColor(0.0, 0.0, 1.0))->withStrokeWidth(2.0), 0.0, 0.0, 10.0, 10.0);
        $svg = $this->makeSvg([$line]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('0 0 1 RG', $bytes);
        self::assertStringContainsString('2 w', $bytes);
        self::assertStringContainsString("l\nS", $bytes);
    }

    public function testRenderFillAndStrokeEmitsB(): void
    {
        $rect = new SvgRect(null, SvgPaint::default()->withFill(new SvgColor(1.0, 1.0, 0.0))->withStroke(new SvgColor(0.0, 0.0, 0.0)), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $svg = $this->makeSvg([$rect]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString("re\nB", $bytes);
    }

    public function testRenderEvenoddUsesStarVariant(): void
    {
        $path = new SvgPath(null, SvgPaint::default()->withFillRule(FillRule::EVENODD), [new MoveTo(0.0, 0.0), new LineTo(10.0, 0.0), new ClosePath()]);
        $svg = $this->makeSvg([$path]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString("f*", $bytes);
    }

    public function testRenderDashArrayEmitsDashOperator(): void
    {
        $line = new SvgLine(null, SvgPaint::default()->withFillNone()->withStroke(new SvgColor(0.0, 0.0, 0.0))->withStrokeDashArray([4.0, 2.0]), 0.0, 0.0, 10.0, 0.0);
        $svg = $this->makeSvg([$line]);
        $bytes = (new Renderer())->render($svg)['bytes'];
        self::assertStringContainsString('[4 2] 0 d', $bytes);
    }

    public function testRenderOpacityEmitsGsAndRegistersExtGState(): void
    {
        $rect = new SvgRect(null, SvgPaint::default()->withFillOpacity(0.5), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $svg = $this->makeSvg([$rect]);
        $result = (new Renderer())->render($svg);
        self::assertStringContainsString('/Gs0 gs', $result['bytes']);
        self::assertArrayHasKey('Gs0', $result['extGStates']);
        self::assertSame(0.5, $result['extGStates']['Gs0']['ca']);
    }

    public function testRenderDedupsIdenticalOpacityPairs(): void
    {
        $r1 = new SvgRect(null, SvgPaint::default()->withFillOpacity(0.5), 0.0, 0.0, 1.0, 1.0, 0.0, 0.0);
        $r2 = new SvgRect(null, SvgPaint::default()->withFillOpacity(0.5), 0.5, 0.5, 1.0, 1.0, 0.0, 0.0);
        $svg = $this->makeSvg([$r1, $r2]);
        $result = (new Renderer())->render($svg);
        // Only one ExtGState declared, both shapes reference Gs0.
        self::assertCount(1, $result['extGStates']);
        self::assertSame(2, substr_count($result['bytes'], '/Gs0 gs'));
    }

    /**
     * @param list<\DragonOfMercy\PhpPdf\Svg\SvgNode> $children
     */
    private function makeSvg(array $children): SvgMetadata
    {
        return new SvgMetadata(
            new ViewBox(0.0, 0.0, 1.0, 1.0),
            PreserveAspectRatio::default(),
            new SvgGroup(null, $children),
        );
    }
}
