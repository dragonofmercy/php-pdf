<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DocumentRevisionOneTest extends TestCase
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

    public function testRevisionOneHasIdAndContext(): void
    {
        $doc = new Document();
        $doc->addPage();
        $doc->addDocumentTimestamp($this->tsa());

        $m = new ReflectionMethod($doc, 'buildRevisionOne');
        /** @var array{bytes: string, context: \DragonOfMercy\PhpPdf\Signature\RevisionContext} $result */
        $result = $m->invoke($doc);

        self::assertStringContainsString('/ID [<', $result['bytes']);
        self::assertStringEndsWith("%%EOF\n", $result['bytes']);
        $ctx = $result['context'];
        self::assertGreaterThanOrEqual(1, $ctx->maxObjectNumber);
        self::assertNotSame('', $ctx->documentId);
        self::assertGreaterThanOrEqual(1, $ctx->firstPage->objectNumber);
    }
}
