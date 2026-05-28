<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\HumanTextSegment;
use DragonOfMercy\PhpPdf\Barcode\TextAnchor;
use PHPUnit\Framework\TestCase;

final class HumanTextSegmentTest extends TestCase
{
    public function testConstruction(): void
    {
        $s = new HumanTextSegment(
            text: 'CODE39',
            xModule: 50.0,
            yModule: 30.0,
            fontSizeModule: 1.5,
            anchor: TextAnchor::MIDDLE,
        );
        self::assertSame('CODE39', $s->text);
        self::assertSame(50.0, $s->xModule);
        self::assertSame(30.0, $s->yModule);
        self::assertSame(1.5, $s->fontSizeModule);
        self::assertSame(TextAnchor::MIDDLE, $s->anchor);
    }
}
