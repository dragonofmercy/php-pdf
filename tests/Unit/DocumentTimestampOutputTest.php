<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use PHPUnit\Framework\TestCase;

final class DocumentTimestampOutputTest extends TestCase
{
    private function tsa(): Tsa
    {
        $stub = new class implements TsaClient {
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return "\x01\x02\x03\x04";
            }
        };
        return Tsa::withClient($stub);
    }

    public function testStandaloneTimestampProducesTwoRevisions(): void
    {
        $doc = new Document();
        $doc->addPage();
        $doc->addDocumentTimestamp($this->tsa());
        $bytes = $doc->output();

        // Count real xref-table headers only: anchor on the preceding newline so
        // the "startxref\n" keyword (which also ends in "xref\n") is not matched.
        self::assertSame(2, substr_count($bytes, "\nxref\n"));
        self::assertStringContainsString('/Prev', $bytes);
        self::assertSame(2, substr_count($bytes, 'startxref'));
        self::assertStringContainsString('/DocTimeStamp', $bytes);
        self::assertStringContainsString('/SubFilter /ETSI.RFC3161', $bytes);
        self::assertSame(2, substr_count($bytes, '/ID [<'));
        if (preg_match('~/Contents <([0-9A-F]+)>~', $bytes, $m) !== 1) {
            self::fail('DocTimeStamp /Contents not patched');
        }
        self::assertStringStartsWith('01020304', $m[1]);
    }
}
