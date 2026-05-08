<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use PHPUnit\Framework\TestCase;

final class TtfParserTest extends TestCase
{
    public function testRejectsOpenTypeCffWithClearMessage(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('OTF/CFF fonts not supported in this version, use TTF: Inter (regular)');
        TtfParser::parse("OTTO\x00\x00\x00\x00more bytes here", 'Inter (regular)');
    }

    public function testRejectsTrueTypeCollection(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('TrueType collection (.ttc) not supported');
        TtfParser::parse("ttcf\x00\x01\x00\x00more", 'Inter (regular)');
    }

    public function testRejectsUnknownMagicBytes(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid TTF file');
        TtfParser::parse("\xDE\xAD\xBE\xEFmore", 'Inter (regular)');
    }

    public function testRejectsTooShortInput(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid TTF file');
        TtfParser::parse('xy', 'Inter (regular)');
    }
}
