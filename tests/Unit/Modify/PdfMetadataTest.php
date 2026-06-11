<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfMetadataTest extends TestCase
{
    private static function sourceWithInfoAndId(): string
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()
            ->title('Original title')
            ->author('Original author')
            ->creationDate(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->addPage();
        return $doc->output();
    }

    private static function bareSource(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        return $doc->output();
    }

    /** Decode a reopened /Info text-string value (PdfString or UTF-16BE HexString) to UTF-8. */
    private static function infoText(?PdfObject $value): ?string
    {
        if ($value instanceof PdfString) {
            return $value->value();
        }
        if ($value instanceof HexString) {
            $binary = hex2bin($value->hex());
            if ($binary === false) {
                return null;
            }
            if (str_starts_with($binary, "\xFE\xFF")) {
                return mb_convert_encoding(substr($binary, 2), 'UTF-8', 'UTF-16BE');
            }
            return $binary;
        }
        return null;
    }

    private static function infoDictionary(PdfReader $reader): Dictionary
    {
        $info = $reader->resolve($reader->trailer()->get(Name::of('Info')) ?? PdfNull::instance());
        self::assertInstanceOf(Dictionary::class, $info);
        return $info;
    }

    public function testSetTitleAppendsARevisionPreservingOriginalBytes(): void
    {
        $source = self::sourceWithInfoAndId();
        $pdf = PdfEditor::fromBytes($source);
        $bytes = $pdf->setTitle('Amended title')->output();

        // the original bytes stay byte-for-byte intact at the head of the file
        self::assertSame($source, substr($bytes, 0, strlen($source)));
        $reader = PdfReader::fromBytes($bytes);
        $info = self::infoDictionary($reader);
        self::assertSame('Amended title', self::infoText($info->get(Name::of('Title'))));
        // untouched fields are preserved from the source /Info
        self::assertSame('Original author', self::infoText($info->get(Name::of('Author'))));
        // /ID carried through verbatim
        self::assertStringContainsString('/ID [<ABCDEF0123456789ABCDEF0123456789>', $bytes);
    }

    public function testAllSettersLand(): void
    {
        $pdf = PdfEditor::fromBytes(self::sourceWithInfoAndId());
        $bytes = $pdf->setTitle('T')->setAuthor('A')->setSubject('S')->setKeywords('K')->setCreator('C')->output();
        $reader = PdfReader::fromBytes($bytes);
        $info = self::infoDictionary($reader);
        foreach (['Title' => 'T', 'Author' => 'A', 'Subject' => 'S', 'Keywords' => 'K', 'Creator' => 'C'] as $key => $value) {
            self::assertSame($value, self::infoText($info->get(Name::of($key))), $key);
        }
    }

    public function testSourceWithoutInfoGetsANewInfoObject(): void
    {
        $pdf = PdfEditor::fromBytes(self::bareSource());
        $bytes = $pdf->setTitle('Fresh')->output();
        $reader = PdfReader::fromBytes($bytes);
        $info = self::infoDictionary($reader);
        self::assertSame('Fresh', self::infoText($info->get(Name::of('Title'))));
    }

    public function testXmpIsRefreshedWhenCatalogHasMetadata(): void
    {
        // a Document WITH metadata() emits a /Metadata XMP stream
        $pdf = PdfEditor::fromBytes(self::sourceWithInfoAndId());
        $bytes = $pdf->setTitle('XMP title')->output();
        $reader = PdfReader::fromBytes($bytes);
        $metadata = $reader->resolve($reader->catalog()->get(Name::of('Metadata')) ?? PdfNull::instance());
        self::assertInstanceOf(ReadStream::class, $metadata);
        self::assertStringContainsString('XMP title', $reader->decodeStream($metadata));
    }

    public function testOutputWithoutChangesThrows(): void
    {
        $pdf = PdfEditor::fromBytes(self::bareSource());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('No pending changes');
        $pdf->output();
    }

    public function testOutputIsIdempotent(): void
    {
        $pdf = PdfEditor::fromBytes(self::sourceWithInfoAndId())->setTitle('Same');
        self::assertSame($pdf->output(), $pdf->output());
    }

    public function testEncryptedSourceIsRejectedAtOpen(): void
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()->documentId('abcdef0123456789abcdef0123456789');
        $doc->encryption()->userPassword('u')->ownerPassword('o')
            ->withRandomSource(fn (int $n) => str_repeat("\x00", $n));
        $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ncrypted');
        PdfEditor::fromBytes($doc->output());
    }

    public function testSaveWritesAFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phppdf_modify_');
        self::assertIsString($path);
        try {
            PdfEditor::fromBytes(self::sourceWithInfoAndId())->setTitle('Saved')->save($path);
            $reader = PdfReader::fromFile($path);
            self::assertSame(1, $reader->pageCount());
        } finally {
            @unlink($path);
        }
    }

    public function testXrefStreamSourceGetsAStreamRevision(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed.');
        }
        // convert a library file to xref-stream format with qpdf, then modify it
        $in = tempnam(sys_get_temp_dir(), 'phppdf_in_');
        $out = tempnam(sys_get_temp_dir(), 'phppdf_out_');
        self::assertIsString($in);
        self::assertIsString($out);
        try {
            file_put_contents($in, self::sourceWithInfoAndId());
            $process = new Process([$qpdf, '--object-streams=generate', $in, $out]);
            $process->run();
            self::assertSame(0, $process->getExitCode());

            $bytes = PdfEditor::open($out)->setTitle('Stream revision')->output();
            $reader = PdfReader::fromBytes($bytes);
            self::assertTrue($reader->usesXrefStreams());
            $info = self::infoDictionary($reader);
            self::assertSame('Stream revision', self::infoText($info->get(Name::of('Title'))));

            // and qpdf accepts our appended revision
            $check = tempnam(sys_get_temp_dir(), 'phppdf_chk_');
            self::assertIsString($check);
            file_put_contents($check, $bytes);
            $verify = new Process([$qpdf, '--check', $check]);
            $verify->run();
            self::assertSame(0, $verify->getExitCode(), $verify->getOutput() . $verify->getErrorOutput());
            @unlink($check);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
