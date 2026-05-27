<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTextTest extends TestCase
{
    /**
     * @return array{bytes: string, fonts: list<string>}
     */
    private function render(string $svg): array
    {
        $meta = Parser::parse($svg);
        $result = (new Renderer())->render($meta, new FontRegistry());
        return ['bytes' => $result['bytes'], 'fonts' => $result['fonts']];
    }

    public function testEmitsTextOperatorsWithFlippedMatrix(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="10" y="20" font-family="sans-serif" font-size="12">Hi</text></svg>';
        $r = $this->render($svg);
        self::assertStringContainsString("BT\n", $r['bytes']);
        self::assertStringContainsString("ET\n", $r['bytes']);
        self::assertStringContainsString("/F1 12 Tf\n", $r['bytes']);
        self::assertStringContainsString("1 0 0 -1 10 20 Tm\n", $r['bytes']);
        self::assertStringContainsString('(Hi) Tj', $r['bytes']);
        self::assertStringContainsString("0 Tr\n", $r['bytes']);
        self::assertSame(['F1'], $r['fonts']);
    }

    public function testAnchorMiddleShiftsStartByHalfWidth(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="50" y="20" font-size="10" text-anchor="middle">Hello</text></svg>';
        $r = $this->render($svg);
        self::assertMatchesRegularExpression('/1 0 0 -1 (\d+(\.\d+)?) 20 Tm/', $r['bytes']);
        $matched = (bool) preg_match('/1 0 0 -1 (\d+(\.\d+)?) 20 Tm/', $r['bytes'], $m);
        self::assertTrue($matched, 'text matrix not found');
        self::assertLessThan(50.0, (float) $m[1]);
    }

    public function testStrokeOnlyUsesRenderModeOne(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="0" y="10" fill="none" stroke="#000000" font-size="10">x</text></svg>';
        $r = $this->render($svg);
        self::assertStringContainsString("1 Tr\n", $r['bytes']);
    }

    public function testParenthesesAreEscapedInShowText(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="0" y="10" font-size="10">a(b)</text></svg>';
        $r = $this->render($svg);
        self::assertStringContainsString('(a\\(b\\)) Tj', $r['bytes']);
    }
}
