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
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
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

    // --- helpers for the new behavioral tests ---

    /** @param list<GradientStop> $stops */
    private function obbGradient(?SvgMatrix $transform = null, array $stops = [], float $uniformOpacity = 1.0): LinearGradient
    {
        if ($stops === []) {
            $stops = [
                new GradientStop(0.0, SvgColor::fromBytes(255, 0, 0), 1.0),
                new GradientStop(1.0, SvgColor::fromBytes(0, 0, 255), 1.0),
            ];
        }
        return new LinearGradient(0.0, 0.0, 1.0, 0.0, GradientUnits::OBJECT_BOUNDING_BOX, $transform, $stops, $uniformOpacity);
    }

    /** @param list<GradientStop> $stops */
    private function userSpaceGradient(?SvgMatrix $transform = null, array $stops = [], float $uniformOpacity = 1.0): LinearGradient
    {
        if ($stops === []) {
            $stops = [
                new GradientStop(0.0, SvgColor::fromBytes(255, 0, 0), 1.0),
                new GradientStop(1.0, SvgColor::fromBytes(0, 0, 255), 1.0),
            ];
        }
        return new LinearGradient(0.0, 0.0, 100.0, 0.0, GradientUnits::USER_SPACE_ON_USE, $transform, $stops, $uniformOpacity);
    }

    /**
     * @return array{bytes: string, extGStates: array<string, array{ca: float, CA: float, smaskEmbeddedIndex: ?int}>, patterns: array<string, string>}
     */
    private function render100ViewBox(SvgGroup $root): array
    {
        $meta = new SvgMetadata(new ViewBox(0.0, 0.0, 100.0, 100.0), PreserveAspectRatio::default(), $root);
        return (new Renderer())->render($meta);
    }

    // --- new behavioral tests ---

    public function testObjectBoundingBoxDistinguishesBboxSizes(): void
    {
        // Two rects at the same origin but different sizes.
        // objectBoundingBox folds width/height into the pattern /Matrix,
        // so each size produces a distinct pattern dict.
        $r1 = new SvgRect(null, SvgPaint::default()->withFill($this->obbGradient()), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $r2 = new SvgRect(null, SvgPaint::default()->withFill($this->obbGradient()), 0.0, 0.0, 50.0, 50.0, 0.0, 0.0);
        $out = $this->render100ViewBox(new SvgGroup(null, [$r1, $r2]));
        self::assertCount(2, $out['patterns'], 'Different bbox dimensions must produce different pattern dicts');
    }

    public function testUserSpaceOnUseIgnoresBboxSize(): void
    {
        // Two rects at the same origin but different sizes.
        // userSpaceOnUse only uses shapeCtm (identical for both), so the
        // pattern dict is the same => deduplicated to a single entry.
        $r1 = new SvgRect(null, SvgPaint::default()->withFill($this->userSpaceGradient()), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $r2 = new SvgRect(null, SvgPaint::default()->withFill($this->userSpaceGradient()), 0.0, 0.0, 50.0, 50.0, 0.0, 0.0);
        $out = $this->render100ViewBox(new SvgGroup(null, [$r1, $r2]));
        self::assertCount(1, $out['patterns'], 'userSpaceOnUse with identical shapeCtm must dedup to one pattern');
    }

    public function testGradientTransformAltersPattern(): void
    {
        // Same rect, same userSpaceOnUse gradient, but one has a gradientTransform.
        // The transform is folded into the /Matrix, producing two distinct dicts.
        $r1 = new SvgRect(null, SvgPaint::default()->withFill($this->userSpaceGradient(null)), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $r2 = new SvgRect(null, SvgPaint::default()->withFill($this->userSpaceGradient(SvgMatrix::translate(10.0, 0.0))), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $out = $this->render100ViewBox(new SvgGroup(null, [$r1, $r2]));
        self::assertCount(2, $out['patterns'], 'A gradientTransform must produce a distinct pattern /Matrix');
    }

    public function testNestedGroupTransformAltersPattern(): void
    {
        // child[0]: rect directly under root (shapeCtm = prologue)
        // child[1]: same rect inside a group with translate(20,0)
        //           (shapeCtm = prologue.compose(translate(20,0)))
        // objectBoundingBox bakes shapeCtm into /Matrix, so the two patterns differ.
        $bare = new SvgRect(null, SvgPaint::default()->withFill($this->obbGradient()), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $inner = new SvgRect(null, SvgPaint::default()->withFill($this->obbGradient()), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $group = new SvgGroup(SvgMatrix::translate(20.0, 0.0), [$inner]);
        $out = $this->render100ViewBox(new SvgGroup(null, [$bare, $group]));
        self::assertCount(2, $out['patterns'], 'A parent group transform must propagate into the gradient /Matrix');
    }

    public function testUniformOpacityFoldsIntoExtGState(): void
    {
        // uniformOpacity 0.5 is multiplied into the effective fill opacity,
        // which causes a non-trivial ExtGState entry and a /gs invocation in the stream.
        $stops = [
            new GradientStop(0.0, SvgColor::fromBytes(255, 0, 0), 0.5),
            new GradientStop(1.0, SvgColor::fromBytes(0, 0, 255), 0.5),
        ];
        $gradient = $this->obbGradient(null, $stops, 0.5);
        $rect = new SvgRect(null, SvgPaint::default()->withFill($gradient), 0.0, 0.0, 100.0, 100.0, 0.0, 0.0);
        $out = $this->render100ViewBox(new SvgGroup(null, [$rect]));
        self::assertNotEmpty($out['extGStates'], 'uniformOpacity < 1 must register an ExtGState');
        self::assertStringContainsString(' gs', $out['bytes'], 'bytes must contain a gs operator invocation');
        self::assertStringContainsString('/Pattern cs', $out['bytes'], 'bytes must set the pattern color space');
    }
}
