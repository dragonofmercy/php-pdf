<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;

final class DocumentTemplateOutputTest extends TestCase
{
    private static function sourcePdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::helvetica(), 12);
        $page->text(50, 50, 'Source content');
        return $doc->output();
    }

    private static function xObjectDict(PdfReader $reader, int $pageNo = 1): Dictionary
    {
        $resources = $reader->page($pageNo)->resources;
        self::assertNotNull($resources);
        $xobjects = $reader->resolve($resources->get(Name::of('XObject')) ?? \DragonOfMercy\PhpPdf\Writer\Object\PdfNull::instance());
        self::assertInstanceOf(Dictionary::class, $xobjects);
        return $xobjects;
    }

    public function testOutputContainsFormXObjectAndPageResource(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $page->template($template, 0, 0);
        $bytes = $doc->output();

        $reader = PdfReader::fromBytes($bytes);
        $xobjects = self::xObjectDict($reader);
        $form = $reader->resolve($xobjects->get(Name::of('Tpl1')) ?? \DragonOfMercy\PhpPdf\Writer\Object\PdfNull::instance());
        self::assertInstanceOf(ReadStream::class, $form);
        self::assertEquals(Name::of('Form'), $form->dict->get(Name::of('Subtype')));
        // the copied resources of the source page travel along (the source used Helvetica)
        self::assertInstanceOf(Dictionary::class, $reader->resolve($form->dict->get(Name::of('Resources')) ?? \DragonOfMercy\PhpPdf\Writer\Object\PdfNull::instance()));
        // and the destination page's content invokes it
        $pageContents = $reader->page(1)->contents;
        self::assertNotSame([], $pageContents);
        $pageStream = $reader->resolve($pageContents[0]);
        self::assertInstanceOf(ReadStream::class, $pageStream);
        $content = $reader->decodeStream($pageStream);
        self::assertStringContainsString('/Tpl1 Do', $content);
    }

    public function testTemplateUsedOnTwoPagesIsEmittedOnce(): void
    {
        $doc = new Document(Unit::PT);
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $doc->addPage()->template($template, 0, 0);
        $doc->addPage()->template($template, 0, 0);
        $bytes = $doc->output();

        $reader = PdfReader::fromBytes($bytes);
        $ref1 = self::xObjectDict($reader, 1)->get(Name::of('Tpl1'));
        $ref2 = self::xObjectDict($reader, 2)->get(Name::of('Tpl1'));
        self::assertEquals($ref1, $ref2); // same indirect object
        self::assertSame(1, substr_count($bytes, '/Subtype /Form'));
    }

    public function testTemplateAndImageCoexistInResources(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $page->template($template, 0, 0);
        $page->image(__DIR__ . '/../../Golden/assets/jpeg-rgb-32x16.jpg', 10, 10, 50);
        $bytes = $doc->output();

        $xobjects = self::xObjectDict(PdfReader::fromBytes($bytes));
        self::assertNotNull($xobjects->get(Name::of('Tpl1')));
        self::assertNotNull($xobjects->get(Name::of('Im1')));
    }

    public function testQpdfValidatesTemplateOutput(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage()->template($doc->importPdfBytes(self::sourcePdfBytes())->page(1), 0, 0);
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf_tpl_');
        self::assertIsString($tmp);
        try {
            file_put_contents($tmp, $doc->output());
            Qpdf::assertCheck($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testEncryptedOutputWithTemplateRoundTrips(): void
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()->documentId('abcdef0123456789abcdef0123456789');
        $doc->encryption()
            ->userPassword('user')
            ->ownerPassword('owner')
            ->withRandomSource(fn (int $n) => str_repeat("\x00", $n));
        $doc->addPage()->template($doc->importPdfBytes(self::sourcePdfBytes())->page(1), 0, 0);
        $bytes = $doc->output();
        self::assertStringContainsString('/Encrypt', $bytes);
        // encrypted bytes must NOT contain the cleartext source content stream
        self::assertStringNotContainsString('Source content', $bytes);
    }
}
