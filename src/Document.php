<?php

declare(strict_types=1);

namespace PhpPdf;

use DateTimeImmutable;
use PhpPdf\Document\Encryption;
use PhpPdf\Document\Metadata;
use PhpPdf\Document\MetadataStream;
use PhpPdf\Document\XmpWriter;
use PhpPdf\Encryption\Cipher;
use PhpPdf\Encryption\EncryptedPdfWriter;
use PhpPdf\Encryption\EncryptionDictBuilder;
use PhpPdf\Encryption\EncryptionKey;
use PhpPdf\Encryption\ObjectTransformer;
use PhpPdf\Encryption\PasswordHash;
use PhpPdf\Exception\PdfException;
use PhpPdf\Font\FontRegistry;
use PhpPdf\Font\MetricsRegistry;
use PhpPdf\Writer\Object\CompressedStream;
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

final class Document
{
    private const string VERSION = '0.1-phase1a';

    private const float A4_WIDTH = 595.28;
    private const float A4_HEIGHT = 841.89;

    private const string HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    /** @var list<Page> */
    private array $pages = [];

    private readonly FontRegistry $fontRegistry;
    private readonly MetricsRegistry $metricsRegistry;

    private ?Metadata $metadata = null;
    private ?Encryption $encryption = null;

    public function __construct()
    {
        $this->fontRegistry = new FontRegistry();
        $this->metricsRegistry = new MetricsRegistry();
    }

    public function metadata(): Metadata
    {
        return $this->metadata ??= new Metadata();
    }

    public function encryption(): Encryption
    {
        return $this->encryption ??= new Encryption();
    }

    public function addPage(): Page
    {
        $page = new Page(
            pageWidth: self::A4_WIDTH,
            pageHeight: self::A4_HEIGHT,
            fontRegistry: $this->fontRegistry,
            metricsRegistry: $this->metricsRegistry,
        );
        $this->pages[] = $page;
        return $page;
    }

    public function output(): string
    {
        if ($this->pages === []) {
            throw new PdfException('Document has no pages');
        }

        if ($this->encryption !== null) {
            return $this->outputEncrypted($this->encryption, $this->metadata);
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

        [$pageAndContentObjects, $pageRefs] = $this->buildPagesAndFonts(firstObjectNumber: 3, pagesRef: $pagesRef);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );

        return (new PdfWriter())->write([$catalog, $pages, ...$pageAndContentObjects], $catalog->reference());
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

        [$pageAndContentObjects, $pageRefs] = $this->buildPagesAndFonts(firstObjectNumber: 5, pagesRef: $pagesRef);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );

        $info = IndirectObject::of(3, 0, $this->buildInfoDictionary($effective));

        $xmpXml = (new XmpWriter())->write($effective);
        $metadataStream = IndirectObject::of(4, 0, new MetadataStream($xmpXml));

        $objects = [$catalog, $pages, $info, $metadataStream, ...$pageAndContentObjects];

        $documentId = $effective->documentId ?? $this->deriveDocumentId($effective);

        return $this->assembleWithTrailer(
            objects: $objects,
            root: $catalog->reference(),
            info: $info->reference(),
            documentId: $documentId,
        );
    }

    private function outputEncrypted(Encryption $encryption, ?Metadata $metadata): string
    {
        if ($encryption->userPassword === null || $encryption->ownerPassword === null) {
            throw new PdfException('Both user password and owner password are required for encryption');
        }

        $randomSource = $encryption->randomSource ?? static function (int $n): string {
            if ($n < 1) {
                throw new PdfException('Invalid random byte count: ' . $n);
            }
            return random_bytes($n);
        };

        $cipher = new Cipher();
        $passwordHash = new PasswordHash();
        $encryptionKey = new EncryptionKey(
            userPassword: $encryption->userPassword,
            ownerPassword: $encryption->ownerPassword,
            permissions: $encryption->permissions,
            encryptMetadata: $encryption->encryptMetadata,
            randomSource: $randomSource,
            passwordHash: $passwordHash,
            cipher: $cipher,
        );

        $pagesRef = PdfReference::to(2, 0);
        $hasMetadata = $metadata !== null;

        $objects = [];
        $encryptObjectNumber = $hasMetadata ? 5 : 3;
        $metadataObjectNumber = $hasMetadata ? 4 : null;
        $firstPageObjectNumber = $hasMetadata ? 6 : 4;

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef);
        if ($hasMetadata) {
            $catalogDict = $catalogDict->withEntry(Name::of('Metadata'), PdfReference::to(4, 0));
        }
        $catalog = IndirectObject::of(1, 0, $catalogDict);
        $objects[] = $catalog;

        [$pageAndContentObjects, $pageRefs] = $this->buildPagesAndFonts(
            firstObjectNumber: $firstPageObjectNumber,
            pagesRef: $pagesRef,
        );

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );
        $objects[] = $pages;

        $infoRef = null;
        $effectiveMetadata = null;
        if ($metadata !== null) {
            $effectiveMetadata = clone $metadata;
            $effectiveMetadata->producer ??= 'phppdf ' . self::VERSION;
            $effectiveMetadata->creationDate ??= new DateTimeImmutable();

            $infoObject = IndirectObject::of(3, 0, $this->buildInfoDictionary($effectiveMetadata));
            $objects[] = $infoObject;
            $infoRef = $infoObject->reference();

            $xmpXml = (new XmpWriter())->write($effectiveMetadata);
            $objects[] = IndirectObject::of(4, 0, new MetadataStream($xmpXml));
        }

        $encryptDict = (new EncryptionDictBuilder())->build(
            $encryptionKey,
            $encryption->encryptMetadata,
            $encryption->permissions,
        );
        $encryptObject = IndirectObject::of($encryptObjectNumber, 0, $encryptDict);
        $objects[] = $encryptObject;

        $objects = array_merge($objects, $pageAndContentObjects);

        $documentId = $metadata !== null
            ? ($metadata->documentId ?? $this->deriveDocumentId($effectiveMetadata))
            : bin2hex($randomSource(16));

        $transformer = new ObjectTransformer(
            cipher: $cipher,
            fileKey: $encryptionKey->fileKey(),
            randomSource: $randomSource,
            encryptObjectNumber: $encryptObjectNumber,
            metadataObjectNumber: $metadataObjectNumber,
            encryptMetadata: $encryption->encryptMetadata,
        );

        return (new EncryptedPdfWriter())->write(
            objects: $objects,
            root: $catalog->reference(),
            info: $infoRef,
            encrypt: $encryptObject->reference(),
            documentId: $documentId,
            transformer: $transformer,
        );
    }

    /**
     * Builds:
     *   - page IndirectObjects (with optional /Contents and /Resources entries),
     *   - content-stream IndirectObjects (for pages that drew something),
     *   - font IndirectObjects (one per registered font in the whole doc).
     *
     * All objects share a single numbering starting at $firstObjectNumber.
     *
     * Returns [allObjects, pageRefs].
     *
     * @return array{list<IndirectObject>, list<PdfReference>}
     */
    private function buildPagesAndFonts(int $firstObjectNumber, PdfReference $pagesRef): array
    {
        $objects = [];
        $pageRefs = [];
        $nextObjectNumber = $firstObjectNumber;

        /** @var list<array{Page, int, ?int}> $pending page + its assigned number + optional content number */
        $pending = [];
        foreach ($this->pages as $page) {
            $pageNum = $nextObjectNumber++;
            $contentNum = $page->contentStream()->isEmpty() ? null : $nextObjectNumber++;
            $pending[] = [$page, $pageNum, $contentNum];
            $pageRefs[] = PdfReference::to($pageNum, 0);
        }

        // Reserve object numbers for each registered font after the pages+contents.
        $fontRefs = [];
        foreach ($this->fontRegistry->registeredFonts() as $font) {
            $fontNum = $nextObjectNumber++;
            $shortName = $this->fontRegistry->shortName($font);
            $fontRefs[$shortName] = PdfReference::to($fontNum, 0);
        }

        // Emit page dicts (with /Resources if fonts used on that page), then content streams.
        foreach ($pending as [$page, $pageNum, $contentNum]) {
            $pageDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Page'))
                ->withEntry(Name::of('Parent'), $pagesRef)
                ->withEntry(Name::of('MediaBox'), PdfArray::of(
                    PdfNumber::ofInt(0),
                    PdfNumber::ofInt(0),
                    PdfNumber::ofFloat($page->pageWidth),
                    PdfNumber::ofFloat($page->pageHeight),
                ));

            $pageFonts = $page->fontsUsed();
            if ($pageFonts !== []) {
                $fontDict = Dictionary::empty();
                foreach ($pageFonts as $font) {
                    $shortName = $this->fontRegistry->shortName($font);
                    $fontDict = $fontDict->withEntry(Name::of($shortName), $fontRefs[$shortName]);
                }
                $pageDict = $pageDict->withEntry(
                    Name::of('Resources'),
                    Dictionary::empty()->withEntry(Name::of('Font'), $fontDict),
                );
            }

            if ($contentNum !== null) {
                $pageDict = $pageDict->withEntry(
                    Name::of('Contents'),
                    PdfReference::to($contentNum, 0),
                );
                $objects[] = IndirectObject::of($pageNum, 0, $pageDict);
                $objects[] = IndirectObject::of(
                    $contentNum,
                    0,
                    CompressedStream::of($page->contentStream()->bytes()),
                );
            } else {
                $objects[] = IndirectObject::of($pageNum, 0, $pageDict);
            }
        }

        // Emit font IndirectObjects.
        foreach ($this->fontRegistry->registeredFonts() as $font) {
            $shortName = $this->fontRegistry->shortName($font);
            $fontRef = $fontRefs[$shortName];
            $fontDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Font'))
                ->withEntry(Name::of('Subtype'), Name::of('Type1'))
                ->withEntry(Name::of('BaseFont'), Name::of($font->pdfName()))
                ->withEntry(Name::of('Encoding'), Name::of('WinAnsiEncoding'));
            $objects[] = IndirectObject::of($fontRef->objectNumber, 0, $fontDict);
        }

        return [$objects, $pageRefs];
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
            $dict = $dict->withEntry(Name::of('CreationDate'), PdfString::of($this->formatPdfDate($m->creationDate)));
        }
        if ($m->modDate !== null) {
            $dict = $dict->withEntry(Name::of('ModDate'), PdfString::of($this->formatPdfDate($m->modDate)));
        }
        if ($m->trapped !== null) {
            $dict = $dict->withEntry(Name::of('Trapped'), Name::of($m->trapped ? 'True' : 'False'));
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
