<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use DragonOfMercy\PhpPdf\Svg\Mask\SvgMask;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class RendererMaskTest extends TestCase
{
    public function testRendererEmitsGsOpAndCollectsEmbeddedMask(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m" maskUnits="userSpaceOnUse" x="0" y="0" width="100" height="100">'
            . '<rect x="20" y="20" width="60" height="60" fill="white"/>'
            . '</mask>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#m)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertArrayHasKey('embeddedMasks', $rendered);
        self::assertCount(1, $rendered['embeddedMasks']);

        $entries = $rendered['extGStates'];
        $maskedEntry = null;
        foreach ($entries as $name => $entry) {
            if ($entry['smaskEmbeddedIndex'] !== null) {
                $maskedEntry = $name;
                break;
            }
        }
        self::assertNotNull($maskedEntry, 'expected at least one ExtGState entry with smaskEmbeddedIndex set');
        self::assertStringContainsString('/' . $maskedEntry . ' gs', $rendered['bytes']);
    }

    public function testDegenerateChildBboxFallsBackToNoMask(): void
    {
        // SvgText returns degenerate bbox; mask should fall back to no-mask (render child only).
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m"><rect x="0" y="0" width="100" height="100" fill="white"/></mask>'
            . '</defs>'
            . '<text x="10" y="50" mask="url(#m)">hi</text>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        // Text bbox is degenerate -> renderMasked must fall back to plain renderNode of child.
        // No EmbeddedMask should be recorded, no smask entry emitted.
        self::assertSame([], $rendered['embeddedMasks']);
        foreach ($rendered['extGStates'] as $entry) {
            self::assertNull($entry['smaskEmbeddedIndex']);
        }
    }
}
