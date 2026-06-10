<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Exception;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use PHPUnit\Framework\TestCase;

final class PdfParseExceptionTest extends TestCase
{
    public function testParseExceptionIsAPdfException(): void
    {
        self::assertInstanceOf(PdfException::class, new PdfParseException('bad byte at offset 12'));
    }
}
