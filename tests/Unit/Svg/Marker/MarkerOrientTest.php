<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Marker;

use DragonOfMercy\PhpPdf\Svg\Marker\MarkerOrient;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerOrientMode;
use PHPUnit\Framework\TestCase;

final class MarkerOrientTest extends TestCase
{
    public function testAngleFactory(): void
    {
        $o = MarkerOrient::angle(45.0);
        self::assertSame(MarkerOrientMode::NUMBER, $o->mode);
        self::assertSame(45.0, $o->angleDeg);
    }

    public function testAutoFactory(): void
    {
        $o = MarkerOrient::auto();
        self::assertSame(MarkerOrientMode::AUTO, $o->mode);
    }

    public function testAutoStartReverseFactory(): void
    {
        $o = MarkerOrient::autoStartReverse();
        self::assertSame(MarkerOrientMode::AUTO_START_REVERSE, $o->mode);
    }

    public function testParse(): void
    {
        self::assertSame(MarkerOrientMode::AUTO, MarkerOrient::parse('auto')->mode);
        self::assertSame(MarkerOrientMode::AUTO_START_REVERSE, MarkerOrient::parse('auto-start-reverse')->mode);
        $n = MarkerOrient::parse('30');
        self::assertSame(MarkerOrientMode::NUMBER, $n->mode);
        self::assertSame(30.0, $n->angleDeg);
        self::assertSame(0.0, MarkerOrient::parse(null)->angleDeg);
        self::assertSame(MarkerOrientMode::NUMBER, MarkerOrient::parse(null)->mode);
        self::assertSame(0.0, MarkerOrient::parse('')->angleDeg);
        self::assertSame(0.0, MarkerOrient::parse('garbage')->angleDeg);
    }
}
