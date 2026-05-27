<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use PHPUnit\Framework\TestCase;

final class RendererGradientTest extends TestCase
{
    private function gradient(): LinearGradient
    {
        return new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, null, [
            new GradientStop(0.0, SvgColor::fromBytes(255, 0, 0), 1.0),
            new GradientStop(1.0, SvgColor::fromBytes(0, 0, 255), 1.0),
        ], 1.0);
    }

    public function testFillGradientEmitsPatternPaint(): void
    {
        $rect = new SvgRect(null, SvgPaint::default()->withFill($this->gradient()), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), new SvgGroup(null, [$rect]));
        $out = (new Renderer())->render($meta);
        self::assertStringContainsString('/Pattern cs', $out['bytes']);
        self::assertMatchesRegularExpression('#/P\d+ scn#', $out['bytes']);
        self::assertStringContainsString('f', $out['bytes']);
        self::assertCount(1, $out['patterns']);
    }

    public function testStrokeGradientEmitsPatternStroke(): void
    {
        $paint = SvgPaint::default()->withFillNone()->withStroke($this->gradient());
        $rect = new SvgRect(null, $paint, 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), new SvgGroup(null, [$rect]));
        $out = (new Renderer())->render($meta);
        self::assertStringContainsString('/Pattern CS', $out['bytes']);
        self::assertMatchesRegularExpression('#/P\d+ SCN#', $out['bytes']);
    }

    public function testIdenticalGradientsDedup(): void
    {
        $r1 = new SvgRect(null, SvgPaint::default()->withFill($this->gradient()), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $r2 = new SvgRect(null, SvgPaint::default()->withFill($this->gradient()), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), new SvgGroup(null, [$r1, $r2]));
        $out = (new Renderer())->render($meta);
        self::assertCount(1, $out['patterns']);
    }

    public function testSolidFillUnaffected(): void
    {
        $rect = new SvgRect(null, SvgPaint::default()->withFill(SvgColor::fromBytes(255, 0, 0)), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), new SvgGroup(null, [$rect]));
        $out = (new Renderer())->render($meta);
        self::assertStringContainsString('1 0 0 rg', $out['bytes']);
        self::assertSame([], $out['patterns']);
    }
}
