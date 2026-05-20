<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Form\CheckboxAppearance;
use PHPUnit\Framework\TestCase;

final class CheckboxAppearanceTest extends TestCase
{
    public function testOnStreamContainsZapfDingbatsCheck(): void
    {
        $result = CheckboxAppearance::generate(widthPt: 14.17, heightPt: 14.17, textColor: Color::rgb(0, 0, 0));
        self::assertStringContainsString('/ZaDb', $result['onContent']);
        self::assertStringContainsString('(4) Tj', $result['onContent']);
        self::assertStringContainsString('BT', $result['onContent']);
        self::assertStringContainsString('ET', $result['onContent']);
        self::assertStringContainsString('0 0 0 rg', $result['onContent']);
    }

    public function testOffStreamIsEffectivelyEmpty(): void
    {
        $result = CheckboxAppearance::generate(14.17, 14.17, Color::rgb(0, 0, 0));
        self::assertSame('', trim($result['offContent']));
    }

    public function testBboxMatchesDimensions(): void
    {
        $result = CheckboxAppearance::generate(14.17, 8.5, Color::rgb(0, 0, 0));
        self::assertSame([0.0, 0.0, 14.17, 8.5], $result['bbox']);
    }

    public function testCustomColorEmitted(): void
    {
        $result = CheckboxAppearance::generate(14.17, 14.17, Color::rgb(255, 0, 0));
        self::assertStringContainsString('1 0 0 rg', $result['onContent']);
    }
}
