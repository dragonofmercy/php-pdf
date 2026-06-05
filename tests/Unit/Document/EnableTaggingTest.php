<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class EnableTaggingTest extends TestCase
{
    public function testTaggingDisabledByDefault(): void
    {
        $doc = new Document();
        self::assertFalse($doc->isTaggingEnabled());
        self::assertNull($doc->language());
        self::assertNull($doc->structureTree());
    }

    public function testEnableTaggingIsFluentAndEnables(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->enableTagging('en-US'));
        self::assertTrue($doc->isTaggingEnabled());
        self::assertSame('en-US', $doc->language());
        self::assertNotNull($doc->structureTree());
    }

    public function testEnableTaggingWithoutLanguageLeavesLanguageNull(): void
    {
        $doc = new Document();
        $doc->enableTagging();
        self::assertTrue($doc->isTaggingEnabled());
        self::assertNull($doc->language());
    }

    public function testEnableTaggingRejectsBadLanguage(): void
    {
        $doc = new Document();
        $this->expectException(PdfException::class);
        $doc->enableTagging('not a tag!');
    }

    public function testOffPathOutputUnchanged(): void
    {
        $a = new Document();
        $a->addPage();
        $b = new Document();
        $b->addPage();
        self::assertSame($a->output(), $b->output());
    }

    public function testTaggedOutputDiffersFromUntagged(): void
    {
        $this->markTestIncomplete('wired in Task 8');

        // Task 8 finishes this: a tagged document must NOT serialize identically
        // to an untagged one (it carries /StructTreeRoot, /MarkInfo, etc.).
        //
        // $a = new Document();
        // $a->addPage();
        // $c = new Document();
        // $c->enableTagging();
        // $c->addPage();
        // self::assertNotSame($a->output(), $c->output());
    }
}
