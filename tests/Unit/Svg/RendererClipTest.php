<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\ClipPathUnits;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgClip;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use PHPUnit\Framework\TestCase;

final class RendererClipTest extends TestCase
{
    private function render(SvgClipped $clipped): string
    {
        $meta = new SvgMetadata(
            new ViewBox(0.0, 0.0, 100.0, 100.0),
            PreserveAspectRatio::default(),
            new SvgGroup(null, [$clipped]),
            [],
        );
        return (new Renderer())->render($meta)['bytes'];
    }

    public function testUserSpaceClipEmitsWnThenChild(): void
    {
        $fill = SvgPaint::default()->withFill(new SvgColor(1.0, 0.0, 0.0));
        $rect = new SvgRect(null, $fill, 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $clipCircle = new SvgCircle(null, SvgPaint::default(), 50.0, 50.0, 40.0);
        $clip = new SvgClip(ClipPathUnits::USER_SPACE_ON_USE, null, [$clipCircle], FillRule::NONZERO);
        $bytes = $this->render(new SvgClipped($clip, $rect));

        self::assertStringContainsString("W n\n", $bytes);
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString(' rg', $bytes);
    }

    public function testEvenoddClipEmitsWStarN(): void
    {
        $rect = new SvgRect(null, SvgPaint::default()->withFill(new SvgColor(0.0, 0.0, 1.0)), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $clipRect = new SvgRect(null, SvgPaint::default(), 10.0, 10.0, 30.0, 30.0, 0.0, 0.0);
        $clip = new SvgClip(ClipPathUnits::USER_SPACE_ON_USE, null, [$clipRect], FillRule::EVENODD);
        $bytes = $this->render(new SvgClipped($clip, $rect));

        self::assertStringContainsString("W* n\n", $bytes);
    }
}
