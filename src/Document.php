<?php

declare(strict_types=1);

namespace PhpPdf;

use DateTimeImmutable;
use PhpPdf\Document\Encryption;
use PhpPdf\Document\Metadata;
use PhpPdf\Document\MetadataStream;
use PhpPdf\Document\XmpWriter;
use PhpPdf\Exception\PdfException;
use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\IndirectObject;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfArray;
use PhpPdf\Writer\Object\PdfNumber;
use PhpPdf\Writer\Object\PdfReference;
use PhpPdf\Writer\Object\PdfString;
use PhpPdf\Writer\Object\TextString;
use PhpPdf\Writer\PdfWriter;
use PhpPdf\Writer\Trailer;
use PhpPdf\Writer\XrefTable;

/**
 * Public entry point for building PDF documents. Phase 1a adds optional
 * metadata via metadata() and an auto-generated XMP packet.
 */
final class Document
{
    private const string VERSION = '0.1-phase1a';

    /** A4 portrait in PDF user units (1 unit = 1/72 inch). */
    private const float A4_WIDTH = 595.28;
    private const float A4_HEIGHT = 841.89;

    private const string HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    private int $pageCount = 0;
    private ?Metadata $metadata = null;
    private ?Encryption $encryption = null;

    public function metadata(): Metadata
    {
        return $this->metadata ??= new Metadata();
    }

    public function encryption(): Encryption
    {
        return $this->encryption ??= new Encryption();
    }

    public function addPage(): void
    {
        $this->pageCount++;
    }

    public function output(): string
    {
        if ($this->pageCount === 0) {
            throw new PdfException('Document has no pages');
        }

        return $this->metadata === null
            ? $this->outputWithoutMetadata()
            : $this->outputWithMetadata($this->metadata);
    }

    public function save(string $path): void
    {
        $bytes = $this->output();
        $result = @file_put_contents($path, $bytes);
        if ($result === false) {
            throw new PdfException("Failed to write PDF to {$path}");
        }
    }

    private function outputWithoutMetadata(): string
    {
        $pagesRef = PdfReference::to(2, 0);

        $catalog = IndirectObject::of(
            1,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Catalog'))
                ->withEntry(Name::of('Pages'), $pagesRef),
        );

        [$pageObjects, $pageRefs] = $this->buildPageObjects(firstObjectNumber: 3, pagesRef: $pagesRef);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt($this->pageCount)),
        );

        $objects = [$catalog, $pages, ...$pageObjects];
        return (new PdfWriter())->write($objects, $catalog->reference());
    }

    private function outputWithMetadata(Metadata $metadata): string
    {
        $effective = clone $metadata;
        $effective->producer ??= 'phppdf ' . self::VERSION;
        $effective->creationDate ??= new DateTimeImmutable();

        $pagesRef = PdfReference::to(2, 0);
        $metadataStreamRef = PdfReference::to(4, 0);

        $catalog = IndirectObject::of(
            1,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Catalog'))
                ->withEntry(Name::of('Pages'), $pagesRef)
                ->withEntry(Name::of('Metadata'), $metadataStreamRef),
        );

        [$pageObjects, $pageRefs] = $this->buildPageObjects(firstObjectNumber: 5, pagesRef: $pagesRef);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt($this->pageCount)),
        );

        $info = IndirectObject::of(3, 0, $this->buildInfoDictionary($effective));

        $xmpXml = (new XmpWriter())->write($effective);
        $metadataStream = IndirectObject::of(4, 0, new MetadataStream($xmpXml));

        $objects = [$catalog, $pages, $info, $metadataStream, ...$pageObjects];

        $documentId = $effective->documentId ?? $this->deriveDocumentId($effective);

        return $this->assembleWithTrailer(
            objects: $objects,
            root: $catalog->reference(),
            info: $info->reference(),
            documentId: $documentId,
        );
    }

    /**
     * @return array{list<IndirectObject>, list<PdfReference>}
     */
    private function buildPageObjects(int $firstObjectNumber, PdfReference $pagesRef): array
    {
        $pageRefs = [];
        $pageObjects = [];
        for ($i = 0; $i < $this->pageCount; $i++) {
            $pageObjectNumber = $firstObjectNumber + $i;
            $pageRefs[] = PdfReference::to($pageObjectNumber, 0);
            $pageObjects[] = IndirectObject::of(
                $pageObjectNumber,
                0,
                Dictionary::empty()
                    ->withEntry(Name::of('Type'), Name::of('Page'))
                    ->withEntry(Name::of('Parent'), $pagesRef)
                    ->withEntry(Name::of('MediaBox'), PdfArray::of(
                        PdfNumber::ofInt(0),
                        PdfNumber::ofInt(0),
                        PdfNumber::ofFloat(self::A4_WIDTH),
                        PdfNumber::ofFloat(self::A4_HEIGHT),
                    )),
            );
        }
        return [$pageObjects, $pageRefs];
    }

    private function buildInfoDictionary(Metadata $m): Dictionary
    {
        $dict = Dictionary::empty();
        if ($m->title !== null) {
            $dict = $dict->withEntry(Name::of('Title'), TextString::of($m->title));
        }
        if ($m->author !== null) {
            $dict = $dict->withEntry(Name::of('Author'), TextString::of($m->author));
        }
        if ($m->subject !== null) {
            $dict = $dict->withEntry(Name::of('Subject'), TextString::of($m->subject));
        }
        if ($m->keywords !== null) {
            $dict = $dict->withEntry(Name::of('Keywords'), TextString::of($m->keywords));
        }
        if ($m->creator !== null) {
            $dict = $dict->withEntry(Name::of('Creator'), TextString::of($m->creator));
        }
        if ($m->producer !== null) {
            $dict = $dict->withEntry(Name::of('Producer'), TextString::of($m->producer));
        }
        if ($m->creationDate !== null) {
            $dict = $dict->withEntry(
                Name::of('CreationDate'),
                PdfString::of($this->formatPdfDate($m->creationDate)),
            );
        }
        if ($m->modDate !== null) {
            $dict = $dict->withEntry(
                Name::of('ModDate'),
                PdfString::of($this->formatPdfDate($m->modDate)),
            );
        }
        if ($m->trapped !== null) {
            $dict = $dict->withEntry(
                Name::of('Trapped'),
                Name::of($m->trapped ? 'True' : 'False'),
            );
        }
        return $dict;
    }

    /**
     * @param list<IndirectObject> $objects
     */
    private function assembleWithTrailer(
        array $objects,
        PdfReference $root,
        PdfReference $info,
        string $documentId,
    ): string {
        $xref = new XrefTable();
        $body = self::HEADER;

        foreach ($objects as $object) {
            $xref->recordOffset($object->objectNumber, strlen($body));
            $body .= $object->toBytes();
        }

        $xrefOffset = strlen($body);
        $body .= $xref->toBytes();

        $trailer = new Trailer(
            size: $xref->size(),
            root: $root,
            xrefOffset: $xrefOffset,
            info: $info,
            documentId: $documentId,
        );
        $body .= $trailer->toBytes();

        return $body;
    }

    private function formatPdfDate(DateTimeImmutable $date): string
    {
        $base = $date->format('\D\:YmdHis');
        $offset = $date->getOffset();
        if ($offset === 0) {
            return $base . 'Z';
        }
        $sign = $offset >= 0 ? '+' : '-';
        $h = intdiv(abs($offset), 3600);
        $m = intdiv(abs($offset) % 3600, 60);
        return $base . sprintf("%s%02d'%02d", $sign, $h, $m);
    }

    private function deriveDocumentId(Metadata $m): string
    {
        $iso = static fn (?DateTimeImmutable $d): string => $d === null ? '' : $d->format('c');
        $parts = [
            'title:' . ($m->title ?? ''),
            'author:' . ($m->author ?? ''),
            'subject:' . ($m->subject ?? ''),
            'keywords:' . ($m->keywords ?? ''),
            'creator:' . ($m->creator ?? ''),
            'producer:' . ($m->producer ?? ''),
            'creationDate:' . $iso($m->creationDate),
            'modDate:' . $iso($m->modDate),
        ];
        return md5(implode("\x00", $parts));
    }
}
