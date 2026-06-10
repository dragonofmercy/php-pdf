<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Document\XmpWriter;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Modify\PendingChanges;
use DragonOfMercy\PhpPdf\Modify\RevisionWriter;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;

/**
 * Opens an existing PDF for modification. Changes are written as an APPENDED
 * incremental revision: the original bytes stay byte-for-byte intact, which
 * preserves any existing digital signatures. Encrypted files are rejected.
 *
 * Known limitation: appended pages carry their own /MediaBox, /Resources and
 * /Rotate 0 so nothing harmful is inherited from the existing tree; an
 * inherited /CropBox smaller than that box could still apply, which is
 * harmless in v1.
 */
final class Pdf
{
    private readonly PdfReader $reader;
    private readonly string $bytes;
    private PendingChanges $pending;

    private function __construct(string $bytes)
    {
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new PdfException('Cannot modify a PDF whose %PDF header is not at byte 0; re-save the file first');
        }
        $this->reader = PdfReader::fromBytes($bytes);
        $this->bytes = $bytes;
        $this->pending = new PendingChanges();
    }

    public static function open(string $path): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PdfException("Cannot read PDF file: {$path}");
        }
        return new self($bytes);
    }

    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    public function setTitle(string $title): self
    {
        $this->pending->title = $title;
        return $this;
    }

    public function setAuthor(string $author): self
    {
        $this->pending->author = $author;
        return $this;
    }

    public function setSubject(string $subject): self
    {
        $this->pending->subject = $subject;
        return $this;
    }

    public function setKeywords(string $keywords): self
    {
        $this->pending->keywords = $keywords;
        return $this;
    }

    public function setCreator(string $creator): self
    {
        $this->pending->creator = $creator;
        return $this;
    }

    public function output(): string
    {
        if ($this->pending->isEmpty()) {
            throw new PdfException('No pending changes to write; call a setter or appendPage() first');
        }
        return $this->assembleRevision();
    }

    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->output()) === false) {
            throw new PdfException("Cannot write PDF file: {$path}");
        }
    }

    private function assembleRevision(): string
    {
        $newObjects = [];
        $nextNumber = $this->reader->maxObjectNumber() + 1;

        $rootRef = $this->reader->trailer()->get(Name::of('Root'));
        if (!$rootRef instanceof PdfReference) {
            throw new PdfException('The opened PDF has no indirect /Root reference');
        }
        $trailerEntries = Dictionary::empty()->withEntry(Name::of('Root'), $rootRef);

        // /Info: merge the source entries with the pending setters, reusing the
        // source object number when /Info exists
        $sourceInfoRef = $this->reader->trailer()->get(Name::of('Info'));
        $infoNumber = $sourceInfoRef instanceof PdfReference ? $sourceInfoRef->objectNumber : $nextNumber++;
        if ($sourceInfoRef instanceof PdfReference) {
            $this->guardGenerationZero($sourceInfoRef->objectNumber, '/Info');
        }
        $newObjects[] = IndirectObject::of($infoNumber, 0, $this->mergedInfoDictionary());
        $trailerEntries = $trailerEntries->withEntry(Name::of('Info'), PdfReference::to($infoNumber, 0));

        // /ID carried through verbatim when present
        $id = $this->reader->trailer()->get(Name::of('ID'));
        if ($id !== null) {
            $trailerEntries = $trailerEntries->withEntry(Name::of('ID'), $id);
        }

        // XMP refresh + catalog re-emit when the catalog carries /Metadata
        $catalogMetadata = $this->reader->catalog()->get(Name::of('Metadata'));
        if ($catalogMetadata instanceof PdfReference) {
            $xmpNumber = $nextNumber++;
            $newObjects[] = IndirectObject::of($xmpNumber, 0, new MetadataStream($this->refreshedXmp()));
            $this->guardGenerationZero($rootRef->objectNumber, '/Root');
            $newObjects[] = IndirectObject::of(
                $rootRef->objectNumber,
                0,
                $this->reader->catalog()->withEntry(Name::of('Metadata'), PdfReference::to($xmpNumber, 0)),
            );
        }

        return (new RevisionWriter())->append(
            reader: $this->reader,
            priorBytes: $this->bytes,
            newObjects: $newObjects,
            trailerEntries: $trailerEntries,
            size: max($this->reader->maxObjectNumber() + 1, $nextNumber),
        );
    }

    private function mergedInfoDictionary(): Dictionary
    {
        $dict = Dictionary::empty();
        $source = $this->reader->trailer()->get(Name::of('Info'));
        if ($source !== null) {
            $resolved = $this->reader->resolve($source);
            if ($resolved instanceof Dictionary) {
                $dict = $resolved;
            }
        }
        foreach ($this->pendingInfoEntries() as $key => $value) {
            $dict = $dict->withEntry(Name::of($key), TextString::of($value));
        }
        return $dict;
    }

    /** @return array<string, string> */
    private function pendingInfoEntries(): array
    {
        return array_filter([
            'Title' => $this->pending->title,
            'Author' => $this->pending->author,
            'Subject' => $this->pending->subject,
            'Keywords' => $this->pending->keywords,
            'Creator' => $this->pending->creator,
        ], static fn (?string $v): bool => $v !== null);
    }

    /**
     * Rebuilds the XMP packet from the MERGED metadata (source /Info plus the
     * pending setters, which win). Source dates are not carried in v1 - the
     * XmpWriter tolerates absent dates.
     */
    private function refreshedXmp(): string
    {
        $merged = $this->mergedInfoDictionary();
        $metadata = new Metadata();
        $apply = static function (string $key, callable $set) use ($merged): void {
            $value = $merged->get(Name::of($key));
            $text = $value !== null ? self::decodeText($value) : null;
            if ($text !== null) {
                $set($text);
            }
        };
        $apply('Title', $metadata->title(...));
        $apply('Author', $metadata->author(...));
        $apply('Subject', $metadata->subject(...));
        $apply('Keywords', $metadata->keywords(...));
        $apply('Creator', $metadata->creator(...));
        return (new XmpWriter())->write($metadata);
    }

    /** Decode a PDF text-string object (PdfString or UTF-16BE HexString) to UTF-8, best-effort. */
    private static function decodeText(PdfObject $value): ?string
    {
        if ($value instanceof TextString) {
            return $value->value();
        }
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

    private function guardGenerationZero(int $objectNumber, string $what): void
    {
        $generation = $this->reader->generationOf($objectNumber);
        if ($generation !== 0) {
            throw new PdfException("Cannot rewrite {$what} (object {$objectNumber}): generation {$generation} is not supported");
        }
    }
}
