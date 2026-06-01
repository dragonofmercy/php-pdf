<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use PHPUnit\Framework\TestCase;

final class DocumentAddTimestampTest extends TestCase
{
    private function tsa(): Tsa
    {
        $stub = new class implements TsaClient {
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return 'tok';
            }
        };
        return Tsa::withClient($stub);
    }

    public function testAddDocumentTimestampIsFluent(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->addDocumentTimestamp($this->tsa()));
    }

    public function testTimestampWithoutPagesThrows(): void
    {
        $doc = new Document();
        $doc->addDocumentTimestamp($this->tsa());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~page~i');
        $doc->output();
    }

    public function testTimestampWithEncryptionThrows(): void
    {
        $doc = new Document();
        $doc->addPage();
        $doc->encryption()->userPassword('u')->ownerPassword('o');
        $doc->addDocumentTimestamp($this->tsa());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~encrypt~i');
        $doc->output();
    }
}
