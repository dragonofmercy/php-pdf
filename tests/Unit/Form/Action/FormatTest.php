<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testNumberDefaults(): void
    {
        $f = Format::number();
        self::assertSame('AFNumber_Keystroke(2, 0, 0, 0, "", false);', $f->keystrokeJs());
        self::assertSame('AFNumber_Format(2, 0, 0, 0, "", false);', $f->formatJs());
    }

    public function testNumberNoThousandsAndNegStyle(): void
    {
        $f = Format::number(decimals: 0, thousands: false, negStyle: 1);
        self::assertSame('AFNumber_Keystroke(0, 1, 1, 0, "", false);', $f->keystrokeJs());
        self::assertSame('AFNumber_Format(0, 1, 1, 0, "", false);', $f->formatJs());
    }

    public function testCurrencySuffix(): void
    {
        $f = Format::currency('EUR', 2, prepend: false);
        self::assertSame('AFNumber_Format(2, 0, 0, 0, " EUR", false);', $f->formatJs());
    }

    public function testCurrencyPrepend(): void
    {
        $f = Format::currency('$', 2, prepend: true);
        self::assertSame('AFNumber_Format(2, 0, 0, 0, "$ ", true);', $f->formatJs());
    }

    public function testPercent(): void
    {
        $f = Format::percent(decimals: 1);
        self::assertSame('AFPercent_Keystroke(1, 1);', $f->keystrokeJs());
        self::assertSame('AFPercent_Format(1, 1);', $f->formatJs());
    }

    public function testDate(): void
    {
        $f = Format::date('dd/mm/yyyy');
        self::assertSame('AFDate_KeystrokeEx("dd/mm/yyyy");', $f->keystrokeJs());
        self::assertSame('AFDate_FormatEx("dd/mm/yyyy");', $f->formatJs());
    }

    public function testTimeKnownPattern(): void
    {
        $f = Format::time('HH:MM:ss');
        self::assertSame('AFTime_Keystroke(2);', $f->keystrokeJs());
        self::assertSame('AFTime_Format(2);', $f->formatJs());
    }

    public function testTimeUnknownPatternThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Format::time pattern "zzz" is not a known Adobe time format; use Format::custom');
        Format::time('zzz');
    }

    public function testCustom(): void
    {
        $f = Format::custom('ks();', 'fmt();');
        self::assertSame('ks();', $f->keystrokeJs());
        self::assertSame('fmt();', $f->formatJs());
    }

    public function testNegativeDecimalsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Format decimals cannot be negative, got -1');
        Format::number(decimals: -1);
    }

    public function testEmptyCurrencySymbolThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Format::currency symbol cannot be empty');
        Format::currency('', 2);
    }

    public function testEmptyDatePatternThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Format::date pattern cannot be empty');
        Format::date('');
    }

    public function testEmptyCustomThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Format::custom keystroke and format JavaScript cannot be empty');
        Format::custom('', 'x');
    }
}
