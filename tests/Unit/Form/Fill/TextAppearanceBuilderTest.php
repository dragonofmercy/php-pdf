<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\DefaultAppearance;
use DragonOfMercy\PhpPdf\Form\Fill\Font\Standard14AppearanceFont;
use DragonOfMercy\PhpPdf\Form\Fill\TextAppearanceBuilder;
use PHPUnit\Framework\TestCase;

final class TextAppearanceBuilderTest extends TestCase
{
    private function builder(): TextAppearanceBuilder
    {
        return new TextAppearanceBuilder();
    }

    private function helvetica(): Standard14AppearanceFont
    {
        return new Standard14AppearanceFont(Font::helvetica(), new MetricsRegistry());
    }

    public function testSingleLineLeftAligned(): void
    {
        $r = $this->builder()->build('Paris', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertSame([0.0, 0.0, 100.0, 14.0], $r['bbox']);
        self::assertStringContainsString('/Tx BMC', $r['content']);
        self::assertStringContainsString('/Helv 10 Tf', $r['content']);
        self::assertStringContainsString('(Paris) Tj', $r['content']);
        self::assertStringContainsString('BT', $r['content']);
        self::assertStringContainsString('ET', $r['content']);
        self::assertStringContainsString('EMC', $r['content']);
    }

    public function testEscapesParensAndBackslash(): void
    {
        $r = $this->builder()->build('a(b)c\\d', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringContainsString('(a\\(b\\)c\\\\d) Tj', $r['content']);
    }

    public function testColorOpsEmitted(): void
    {
        $r = $this->builder()->build('x', 50.0, 12.0, DefaultAppearance::parse('1 0 0 rg /Helv 8 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringContainsString('1 0 0 rg', $r['content']);
    }

    public function testEmptyTextProducesNoTj(): void
    {
        $r = $this->builder()->build('', 50.0, 12.0, DefaultAppearance::parse('0 g /Helv 8 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringNotContainsString('Tj', $r['content']);
        self::assertStringContainsString('/Tx BMC', $r['content']);
    }

    public function testCenterAlignPushesTextRight(): void
    {
        $b = new TextAppearanceBuilder();
        $left = $b->build('Hi', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        $center = $b->build('Hi', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 1, multiline: false);
        $right = $b->build('Hi', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 2, multiline: false);
        // X offset of the Td grows left < center < right
        self::assertTrue($this->tdX($left['content']) < $this->tdX($center['content']));
        self::assertTrue($this->tdX($center['content']) < $this->tdX($right['content']));
    }

    public function testAutoSizeEmitsConcreteSize(): void
    {
        $b = new TextAppearanceBuilder();
        $r = $b->build('X', 100.0, 20.0, DefaultAppearance::parse('0 g /Helv 0 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringNotContainsString('/Helv 0 Tf', $r['content']); // not the auto-size sentinel
        self::assertMatchesRegularExpression('#/Helv \d#', $r['content']);
    }

    public function testMultilineWrapsIntoMultipleTj(): void
    {
        $b = new TextAppearanceBuilder();
        $long = 'one two three four five six seven eight nine ten eleven twelve';
        $r = $b->build($long, 60.0, 60.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: true);
        self::assertGreaterThan(1, substr_count($r['content'], ' Tj'));
    }

    public function testAutoSizeClampsToBounds(): void
    {
        $b = new TextAppearanceBuilder();
        $da = DefaultAppearance::parse('0 g /Helv 0 Tf');

        // heightPt = 20: min(12, 20-4) = 12 -> upper clamp.
        $tall = $b->build('X', 100.0, 20.0, $da, $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertSame(1, preg_match('#/Helv (\d+(?:\.\d+)?) Tf#', $tall['content'], $m));
        self::assertSame(12.0, (float) ($m[1] ?? 0));

        // heightPt = 6: max(4, 6-4) = max(4, 2) = 4 -> lower clamp.
        $short = $b->build('X', 100.0, 6.0, $da, $this->helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertSame(1, preg_match('#/Helv (\d+(?:\.\d+)?) Tf#', $short['content'], $m));
        self::assertSame(4.0, (float) ($m[1] ?? 0));
    }

    public function testMultilineOverWideSingleWordGetsOwnLine(): void
    {
        $b = new TextAppearanceBuilder();
        $word = str_repeat('a', 32); // 32-character word, far wider than 20pt
        $r = $b->build($word, 20.0, 60.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: true);
        self::assertSame(1, substr_count($r['content'], ' Tj'));
    }

    public function testMultilineEmptyTextProducesNoTjAndNoTL(): void
    {
        $b = new TextAppearanceBuilder();
        $r = $b->build('', 100.0, 60.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            $this->helvetica(), 'Helv', quadding: 0, multiline: true);
        self::assertStringContainsString('/Tx BMC', $r['content']);
        self::assertStringContainsString('ET', $r['content']);
        self::assertStringNotContainsString('Tj', $r['content']);
        self::assertStringNotContainsString('TL', $r['content']);
    }

    private function tdX(string $content): float
    {
        self::assertSame(1, preg_match('/^([0-9.]+) [0-9.]+ Td$/m', $content, $m));
        return isset($m[1]) ? (float) $m[1] : 0.0;
    }
}
