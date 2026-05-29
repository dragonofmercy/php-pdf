<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Page\Operators;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use PHPUnit\Framework\TestCase;

final class EncodeShowTextTest extends TestCase
{
    public function testStandardEncodeMatchesOperatorsShowText(): void
    {
        $font = Font::helvetica();
        $engine = new StandardFontEngine($font, (new MetricsRegistry())->metricsFor($font));
        $expected = Operators::showText(WinAnsiEncoder::encode('Hello'));
        self::assertSame($expected, $engine->encodeShowText('Hello'));
    }
}
