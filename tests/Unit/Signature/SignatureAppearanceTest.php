<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\SignatureAppearance;
use PHPUnit\Framework\TestCase;

final class SignatureAppearanceTest extends TestCase
{
    public function testHoldsValues(): void
    {
        $a = new SignatureAppearance(1, 20.0, 30.0, 80.0, 25.0, 'Signed by X');
        self::assertSame(1, $a->pageIndex);
        self::assertSame(20.0, $a->x);
        self::assertSame(25.0, $a->height);
        self::assertSame('Signed by X', $a->caption);
    }

    public function testNullCaptionAllowed(): void
    {
        self::assertNull((new SignatureAppearance(0, 0.0, 0.0, 10.0, 10.0))->caption);
    }

    public function testNegativePageIndexRejected(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~page index.*-1~');
        new SignatureAppearance(-1, 0.0, 0.0, 10.0, 10.0);
    }

    public function testNonPositiveWidthRejected(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~width.*0~');
        new SignatureAppearance(0, 0.0, 0.0, 0.0, 10.0);
    }

    public function testNonPositiveHeightRejected(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~height.*-5~');
        new SignatureAppearance(0, 0.0, 0.0, 10.0, -5.0);
    }
}
