<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\Calculate;
use PHPUnit\Framework\TestCase;

final class CalculateTest extends TestCase
{
    public function testSum(): void
    {
        self::assertSame(
            'AFSimple_Calculate("SUM", new Array("a", "b"));',
            Calculate::sum(['a', 'b'])->js(),
        );
    }

    public function testProduct(): void
    {
        self::assertSame(
            'AFSimple_Calculate("PRD", new Array("qty", "price"));',
            Calculate::product(['qty', 'price'])->js(),
        );
    }

    public function testAverageMinMax(): void
    {
        self::assertSame('AFSimple_Calculate("AVG", new Array("a"));', Calculate::average(['a'])->js());
        self::assertSame('AFSimple_Calculate("MIN", new Array("a"));', Calculate::min(['a'])->js());
        self::assertSame('AFSimple_Calculate("MAX", new Array("a"));', Calculate::max(['a'])->js());
    }

    public function testFieldNameEscaped(): void
    {
        self::assertSame(
            'AFSimple_Calculate("SUM", new Array("a\"b"));',
            Calculate::sum(['a"b'])->js(),
        );
    }

    public function testCustom(): void
    {
        self::assertSame('event.value = 1;', Calculate::custom('event.value = 1;')->js());
    }

    public function testEmptyFieldsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Calculate requires at least one field name');
        Calculate::sum([]);
    }

    public function testEmptyFieldNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Calculate field names must be non-empty strings');
        Calculate::sum(['a', '']);
    }

    public function testEmptyCustomThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Calculate::custom JavaScript cannot be empty');
        Calculate::custom('');
    }
}
