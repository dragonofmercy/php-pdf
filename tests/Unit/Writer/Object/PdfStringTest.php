<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Writer\Object;

use PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class PdfStringTest extends TestCase
{
    public function testSimpleStringIsWrappedInParentheses(): void
    {
        self::assertSame('(hello)', PdfString::of('hello')->toBytes());
    }

    public function testBackslashIsEscaped(): void
    {
        self::assertSame('(a\\\\b)', PdfString::of('a\\b')->toBytes());
    }

    public function testParenthesesAreEscaped(): void
    {
        self::assertSame('(He said \\(hi\\))', PdfString::of('He said (hi)')->toBytes());
    }

    public function testNewlineIsEscaped(): void
    {
        self::assertSame('(a\\nb)', PdfString::of("a\nb")->toBytes());
    }

    public function testEmptyStringIsJustParentheses(): void
    {
        self::assertSame('()', PdfString::of('')->toBytes());
    }
}
