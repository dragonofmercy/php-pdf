<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\Validate;
use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase
{
    public function testRangeBothBounds(): void
    {
        self::assertSame('AFRange_Validate(true, 0, true, 100);', Validate::range(0, 100)->js());
    }

    public function testRangeMinOnly(): void
    {
        self::assertSame('AFRange_Validate(true, 0, false, 0);', Validate::range(0, null)->js());
    }

    public function testRangeMaxOnly(): void
    {
        self::assertSame('AFRange_Validate(false, 0, true, 100);', Validate::range(null, 100)->js());
    }

    public function testRangeFloatBounds(): void
    {
        self::assertSame('AFRange_Validate(true, 1.5, true, 9.5);', Validate::range(1.5, 9.5)->js());
    }

    public function testCustom(): void
    {
        self::assertSame('event.rc = true;', Validate::custom('event.rc = true;')->js());
    }

    public function testRangeBothNullThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Validate::range requires at least one of min or max');
        Validate::range(null, null);
    }

    public function testRangeMinGreaterThanMaxThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Validate::range min (10) cannot exceed max (5)');
        Validate::range(10, 5);
    }

    public function testEmptyCustomThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Validate::custom JavaScript cannot be empty');
        Validate::custom('');
    }
}
