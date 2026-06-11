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
}
