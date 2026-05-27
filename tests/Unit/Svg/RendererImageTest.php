<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgImage;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use PHPUnit\Framework\TestCase;

final class RendererImageTest extends TestCase
{
    private function render(SvgImage $img): string
    {
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), new SvgGroup(null, [$img]));
        return (new Renderer())->render($meta)['bytes'];
    }

    public function testStretchNonePlacementAndDo(): void
    {
        $ar = new PreserveAspectRatio(Align::NONE, MeetOrSlice::MEET);
        $img = new SvgImage(null, 10.0, 20.0, 80.0, 40.0, $ar, 1.0, 0, 4, 2);
        $bytes = $this->render($img);
        self::assertStringContainsString('80 0 0 -40 10 60 cm', $bytes);
        self::assertStringContainsString('/Im0 Do', $bytes);
        self::assertStringNotContainsString('W n', $bytes);
    }

    public function testMeetLetterboxCentered(): void
    {
        $ar = new PreserveAspectRatio(Align::X_MID_Y_MID, MeetOrSlice::MEET);
        $img = new SvgImage(null, 10.0, 20.0, 80.0, 40.0, $ar, 1.0, 0, 2, 4);
        $bytes = $this->render($img);
        self::assertStringContainsString('20 0 0 -40 40 60 cm', $bytes);
        self::assertStringNotContainsString('W n', $bytes);
    }

    public function testSliceClipsToViewport(): void
    {
        $ar = new PreserveAspectRatio(Align::X_MID_Y_MID, MeetOrSlice::SLICE);
        $img = new SvgImage(null, 10.0, 20.0, 80.0, 40.0, $ar, 1.0, 0, 2, 4);
        $bytes = $this->render($img);
        self::assertStringContainsString('10 20 80 40 re', $bytes);
        self::assertStringContainsString('W n', $bytes);
        self::assertStringContainsString('80 0 0 -160 10 120 cm', $bytes);
    }

    public function testOpacityEmitsExtGState(): void
    {
        $img = new SvgImage(null, 0.0, 0.0, 10.0, 10.0, PreserveAspectRatio::default(), 0.5, 0, 4, 2);
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), new SvgGroup(null, [$img]));
        $out = (new Renderer())->render($meta);
        self::assertStringContainsString('gs', $out['bytes']);
        self::assertNotSame([], $out['extGStates']);
    }

    public function testTransformEmitsCm(): void
    {
        $img = new SvgImage(SvgMatrix::translate(5.0, 7.0), 0.0, 0.0, 10.0, 10.0, PreserveAspectRatio::default(), 1.0, 0, 2, 2);
        $bytes = $this->render($img);
        self::assertStringContainsString('1 0 0 1 5 7 cm', $bytes);
    }

    public function testTwoNodesSameIndexEmitTwoDo(): void
    {
        $a = new SvgImage(null, 0.0, 0.0, 10.0, 10.0, PreserveAspectRatio::default(), 1.0, 0, 2, 2);
        $b = new SvgImage(null, 20.0, 0.0, 10.0, 10.0, PreserveAspectRatio::default(), 1.0, 0, 2, 2);
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), new SvgGroup(null, [$a, $b]));
        $bytes = (new Renderer())->render($meta)['bytes'];
        self::assertSame(2, substr_count($bytes, '/Im0 Do'));
    }
}
