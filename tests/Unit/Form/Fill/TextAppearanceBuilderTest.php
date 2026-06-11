<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\DefaultAppearance;
use DragonOfMercy\PhpPdf\Form\Fill\TextAppearanceBuilder;
use PHPUnit\Framework\TestCase;

final class TextAppearanceBuilderTest extends TestCase
{
    private function builder(): TextAppearanceBuilder
    {
        return new TextAppearanceBuilder(new MetricsRegistry());
    }

    public function testSingleLineLeftAligned(): void
    {
        $r = $this->builder()->build('Paris', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            Font::helvetica(), 'Helv', quadding: 0, multiline: false);
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
            Font::helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringContainsString('(a\\(b\\)c\\\\d) Tj', $r['content']);
    }

    public function testColorOpsEmitted(): void
    {
        $r = $this->builder()->build('x', 50.0, 12.0, DefaultAppearance::parse('1 0 0 rg /Helv 8 Tf'),
            Font::helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringContainsString('1 0 0 rg', $r['content']);
    }

    public function testEmptyTextProducesNoTj(): void
    {
        $r = $this->builder()->build('', 50.0, 12.0, DefaultAppearance::parse('0 g /Helv 8 Tf'),
            Font::helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringNotContainsString('Tj', $r['content']);
        self::assertStringContainsString('/Tx BMC', $r['content']);
    }

    public function testCenterAlignPushesTextRight(): void
    {
        $b = new TextAppearanceBuilder(new MetricsRegistry());
        $left = $b->build('Hi', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            Font::helvetica(), 'Helv', quadding: 0, multiline: false);
        $center = $b->build('Hi', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            Font::helvetica(), 'Helv', quadding: 1, multiline: false);
        $right = $b->build('Hi', 100.0, 14.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            Font::helvetica(), 'Helv', quadding: 2, multiline: false);
        // X offset of the Td grows left < center < right
        self::assertTrue($this->tdX($left['content']) < $this->tdX($center['content']));
        self::assertTrue($this->tdX($center['content']) < $this->tdX($right['content']));
    }

    public function testAutoSizeEmitsConcreteSize(): void
    {
        $b = new TextAppearanceBuilder(new MetricsRegistry());
        $r = $b->build('X', 100.0, 20.0, DefaultAppearance::parse('0 g /Helv 0 Tf'),
            Font::helvetica(), 'Helv', quadding: 0, multiline: false);
        self::assertStringNotContainsString('/Helv 0 Tf', $r['content']); // not the auto-size sentinel
        self::assertMatchesRegularExpression('#/Helv \d#', $r['content']);
    }

    public function testMultilineWrapsIntoMultipleTj(): void
    {
        $b = new TextAppearanceBuilder(new MetricsRegistry());
        $long = 'one two three four five six seven eight nine ten eleven twelve';
        $r = $b->build($long, 60.0, 60.0, DefaultAppearance::parse('0 g /Helv 10 Tf'),
            Font::helvetica(), 'Helv', quadding: 0, multiline: true);
        self::assertGreaterThan(1, substr_count($r['content'], ' Tj'));
    }

    private function tdX(string $content): float
    {
        self::assertSame(1, preg_match('/^([0-9.]+) [0-9.]+ Td$/m', $content, $m));
        return isset($m[1]) ? (float) $m[1] : 0.0;
    }
}
