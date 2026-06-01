<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\DocTimeStampDictionaryEmitter;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use PHPUnit\Framework\TestCase;

final class DocTimeStampDictionaryEmitterTest extends TestCase
{
    public function testEmitsDocTimeStampDictWithPlaceholders(): void
    {
        $obj = (new DocTimeStampDictionaryEmitter())->emit(16384, 9);
        $bytes = $obj->toBytes();
        self::assertSame(9, $obj->objectNumber);
        self::assertStringContainsString('/Type /DocTimeStamp', $bytes);
        self::assertStringContainsString('/Filter /Adobe.PPKLite', $bytes);
        self::assertStringContainsString('/SubFilter /ETSI.RFC3161', $bytes);
        self::assertStringContainsString(SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER, $bytes);
        self::assertStringContainsString('/Contents <' . str_repeat('0', 16384 * 2) . '>', $bytes);
    }
}
