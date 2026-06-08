<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use PHPUnit\Framework\TestCase;

final class EnablePdfALevelATest extends TestCase
{
    public function testLevelAEnablesTagging(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A2A, 'en-US');

        self::assertTrue($doc->isTaggingEnabled());
        self::assertSame('en-US', $doc->language());
        self::assertNotNull($doc->structureTree());
    }

    public function testLevelBDoesNotEnableTagging(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A2B);

        self::assertFalse($doc->isTaggingEnabled());
        self::assertNull($doc->structureTree());
    }

    public function testLevelAWithoutLangStillEnablesTagging(): void
    {
        $doc = new Document();
        $doc->enablePdfA(PdfALevel::A3A);

        self::assertTrue($doc->isTaggingEnabled());
        self::assertNull($doc->language());
    }

    public function testComboKeepsLanguageRegardlessOfOrder(): void
    {
        // A later enableTagging(null) - e.g. enablePdfUA() with no arg - must not
        // wipe the language set by enablePdfA(A2A, 'en-US'). Order-independent.
        $a = (new Document())->enablePdfA(PdfALevel::A2A, 'en-US')->enablePdfUA();
        self::assertSame('en-US', $a->language());

        $b = (new Document())->enablePdfUA('en-US')->enablePdfA(PdfALevel::A2A);
        self::assertSame('en-US', $b->language());
    }
}
