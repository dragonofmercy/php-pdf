<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererGradientStopAlphaTest extends TestCase
{
    public function testVaryingAlphaEmitsSoftMaskAndAlphaPattern(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<linearGradient id="g" x1="0" y1="0" x2="1" y2="0">'
            .   '<stop offset="0" stop-color="red" stop-opacity="1"/>'
            .   '<stop offset="1" stop-color="red" stop-opacity="0"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="url(#g)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);

        self::assertCount(1, $rendered['embeddedMasks']);
        $mask = $rendered['embeddedMasks'][0];

        self::assertNotEmpty($mask->patterns);
        $patterns = $mask->patterns;
        $alphaDict = reset($patterns);
        self::assertIsString($alphaDict);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $alphaDict);

        self::assertStringContainsString('/Pattern cs', $mask->contentBytes);
        self::assertStringContainsString('re', $mask->contentBytes);
        self::assertStringContainsString(" f", $mask->contentBytes);

        $foundSmaskEntry = false;
        foreach ($rendered['extGStates'] as $entry) {
            if ($entry['smaskEmbeddedIndex'] === 0) {
                $foundSmaskEntry = true;
                break;
            }
        }
        self::assertTrue($foundSmaskEntry, 'expected ExtGState entry referencing the alpha mask');

        self::assertMatchesRegularExpression('#/Gs\d+ gs\s+/Pattern cs\s+/P\d+ scn#', $rendered['bytes']);
    }

    public function testUniformAlphaDoesNotEmitSoftMask(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<linearGradient id="g" x1="0" y1="0" x2="1" y2="0">'
            .   '<stop offset="0" stop-color="red" stop-opacity="0.5"/>'
            .   '<stop offset="1" stop-color="blue" stop-opacity="0.5"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="url(#g)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertSame([], $rendered['embeddedMasks']);
    }

    public function testRadialVaryingAlphaAlsoEmitsSMask(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<radialGradient id="g" cx="0.5" cy="0.5" r="0.5">'
            .   '<stop offset="0" stop-color="blue" stop-opacity="1"/>'
            .   '<stop offset="1" stop-color="blue" stop-opacity="0"/>'
            . '</radialGradient>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="url(#g)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertCount(1, $rendered['embeddedMasks']);
        $patterns = $rendered['embeddedMasks'][0]->patterns;
        $alphaDict = reset($patterns);
        self::assertIsString($alphaDict);
        self::assertStringContainsString('/ShadingType 3', $alphaDict);
    }

    public function testStrokeVaryingAlphaUsesUpperCaseOps(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<linearGradient id="g" x1="0" y1="0" x2="1" y2="0">'
            .   '<stop offset="0" stop-color="green" stop-opacity="1"/>'
            .   '<stop offset="1" stop-color="green" stop-opacity="0"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<rect x="10" y="10" width="80" height="80" fill="none" stroke="url(#g)" stroke-width="4"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertCount(1, $rendered['embeddedMasks']);
        self::assertMatchesRegularExpression('#/Gs\d+ gs\s+/Pattern CS\s+/P\d+ SCN#', $rendered['bytes']);
    }
}
