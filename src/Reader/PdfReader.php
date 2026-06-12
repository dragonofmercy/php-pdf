<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Encryption\Reader\EncryptionParams;
use DragonOfMercy\PhpPdf\Encryption\Reader\ObjectDecryptor;
use DragonOfMercy\PhpPdf\Encryption\Reader\StandardSecurityHandler;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Filter\StreamDecoder;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Reads an existing PDF file (encrypted files are transparently decrypted):
 * cross-reference data, lazy object resolution with caching, and the page
 * tree. This is the foundation for template import and incremental
 * modification of existing files.
 */
final class PdfReader
{
    private const int HEADER_SEARCH_WINDOW = 1024;
    private const int OFFSET_RECOVERY_WINDOW = 512;
    private const int MAX_REFERENCE_DEPTH = 32;

    private readonly int $headerOffset;
    private readonly string $headerVersion;
    private readonly XrefData $xref;
    private readonly StreamDecoder $decoder;
    private readonly ObjectParser $objectParser;
    /** @var array<int, PdfObject> */
    private array $cache = [];
    /** @var array<int, true> */
    private array $resolving = [];
    /** @var array<int, ObjectStreamReader> */
    private array $objectStreams = [];
    /** @var ?list<ReadPage> */
    private ?array $pages = null;
    private ?ObjectDecryptor $decryptor = null;
    private ?StandardSecurityHandler $securityHandler = null;
    private bool $encrypted = false;

    private function __construct(private readonly string $bytes, ?string $password = null)
    {
        $headerAt = strpos(substr($bytes, 0, self::HEADER_SEARCH_WINDOW), '%PDF-');
        if ($headerAt === false) {
            throw new PdfParseException(sprintf(
                'No %%PDF- header found in the first %d bytes',
                self::HEADER_SEARCH_WINDOW,
            ));
        }
        $this->headerOffset = $headerAt;
        $this->headerVersion = preg_match('/^%PDF-(\d+\.\d+)/', substr($bytes, $headerAt, 16), $match) === 1 ? $match[1] : '1.4';
        $this->xref = (new XrefReader($bytes, $headerAt))->read();
        $this->decoder = new StreamDecoder();
        $this->objectParser = new ObjectParser(new Lexer($bytes), fn (PdfReference $ref): PdfObject => $this->resolve($ref));
        if ($this->xref->trailer->get(Name::of('Encrypt')) !== null) {
            $this->setUpDecryption($password);
        }
    }

    public static function fromBytes(string $bytes, ?string $password = null): self
    {
        return new self($bytes, $password);
    }

    public static function fromFile(string $path, ?string $password = null): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PdfException("Cannot read PDF file: {$path}");
        }
        return new self($bytes, $password);
    }

    /**
     * Wires the decryption subsystem from the trailer /Encrypt dictionary.
     *
     * Ordering matters: the /Encrypt object (and trailer /ID) must be
     * materialized while $this->decryptor is still null, so resolving them does
     * NOT attempt to decrypt them - the /Encrypt strings are stored in the clear
     * by the spec, and they are cached undecrypted here, which is correct.
     */
    private function setUpDecryption(?string $password): void
    {
        $encrypt = $this->xref->trailer->get(Name::of('Encrypt'));
        if ($encrypt instanceof PdfReference) {
            $encryptObjectNumber = $encrypt->objectNumber;
            $encryptDict = $this->resolve($encrypt);
        } else {
            $encryptObjectNumber = -1;
            $encryptDict = $encrypt === null ? PdfNull::instance() : $encrypt;
        }
        if (!$encryptDict instanceof Dictionary) {
            throw new PdfParseException('/Encrypt does not resolve to a dictionary');
        }

        $params = EncryptionParams::fromTrailer($encryptDict, $this->xref->trailer, $this->resolve(...));
        $handler = (new StandardSecurityHandler($params, new PasswordHash()))->authenticate($password);
        $this->securityHandler = $handler;

        $metadataObjectNumber = $this->metadataObjectNumber();

        $this->decryptor = new ObjectDecryptor(
            $handler,
            $encryptObjectNumber,
            $metadataObjectNumber,
            $params->encryptMetadata,
        );
        $this->encrypted = true;

        // Setup materialized a few objects (the /Encrypt dictionary, the catalog
        // when looking up /Metadata) while the decryptor was still null, so they
        // were cached undecrypted. Drop the cache so every object is re-fetched
        // through the decryptor on demand; the /Encrypt object will be re-fetched
        // and correctly skipped by ObjectDecryptor via its object number.
        $this->cache = [];
        $this->objectStreams = [];
    }

    /**
     * Object number of the catalog's /Metadata stream, or null when absent.
     * Only consulted to keep an unencrypted /Metadata stream in the clear
     * (EncryptMetadata=false); resolved here while the decryptor is still null.
     */
    private function metadataObjectNumber(): ?int
    {
        $root = $this->xref->trailer->get(Name::of('Root'));
        if ($root === null) {
            return null;
        }
        $catalog = $this->resolve($root);
        if (!$catalog instanceof Dictionary) {
            return null;
        }
        $metadata = $catalog->get(Name::of('Metadata'));
        return $metadata instanceof PdfReference ? $metadata->objectNumber : null;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * The authenticated Standard security handler when the source is encrypted,
     * else null. Exposes the recovered file key and per-object key derivation so
     * the editor can re-encrypt an appended incremental revision under the same
     * scheme.
     */
    public function securityHandler(): ?StandardSecurityHandler
    {
        return $this->securityHandler;
    }

    public function trailer(): Dictionary
    {
        return $this->xref->trailer;
    }

    public function catalog(): Dictionary
    {
        $root = $this->xref->trailer->get(Name::of('Root'));
        if ($root === null) {
            throw new PdfParseException('The trailer has no /Root entry');
        }
        $catalog = $this->resolve($root);
        if (!$catalog instanceof Dictionary) {
            throw new PdfParseException('/Root does not resolve to a dictionary');
        }
        return $catalog;
    }

    /** Whether the file's NEWEST revision uses a cross-reference stream (an appended revision must match, ISO 32000-1 7.5.8). */
    public function usesXrefStreams(): bool
    {
        return $this->xref->usesXrefStreams;
    }

    /** Byte offset (relative to the %PDF header) of the newest revision's xref - the /Prev of an appended revision. */
    public function lastStartxref(): int
    {
        return $this->xref->startxref;
    }

    /** Highest object number in use; new objects start at maxObjectNumber() + 1. */
    public function maxObjectNumber(): int
    {
        $highest = $this->xref->entries === [] ? 0 : max(array_keys($this->xref->entries));
        $size = DictReader::int($this->xref->trailer, 'Size', $this->resolve(...)) ?? 0;
        return max($highest, $size - 1);
    }

    /** Generation recorded in the xref for an in-file object (0 when absent). */
    public function generationOf(int $objectNumber): int
    {
        $entry = $this->xref->entries[$objectNumber] ?? null;
        return $entry !== null && $entry->kind === XrefEntryKind::InFile ? $entry->second : 0;
    }

    public function version(): string
    {
        $override = DictReader::name($this->catalog(), 'Version', $this->resolve(...));
        return $override ?? $this->headerVersion;
    }

    /** Follows reference chains until a direct object is reached. */
    public function resolve(PdfObject $value): PdfObject
    {
        $depth = 0;
        while ($value instanceof PdfReference) {
            if (++$depth > self::MAX_REFERENCE_DEPTH) {
                throw new PdfParseException('Reference chain deeper than ' . self::MAX_REFERENCE_DEPTH . " while resolving object {$value->objectNumber}");
            }
            $value = $this->object($value->objectNumber);
        }
        return $value;
    }

    /** Returns the object's payload; PdfNull for free or absent objects. */
    public function object(int $objectNumber): PdfObject
    {
        if (isset($this->cache[$objectNumber])) {
            return $this->cache[$objectNumber];
        }
        $entry = $this->xref->entries[$objectNumber] ?? null;
        if ($entry === null || $entry->kind === XrefEntryKind::Free) {
            return PdfNull::instance();
        }
        if (isset($this->resolving[$objectNumber])) {
            throw new PdfParseException("Circular reference while loading object {$objectNumber}");
        }
        $this->resolving[$objectNumber] = true;
        try {
            $object = $entry->kind === XrefEntryKind::InFile
                ? $this->parseAt($entry->first, $objectNumber)
                : $this->fromObjectStream($entry->first, $entry->second);
            // Decrypt in-file objects only: objects pulled from an /ObjStm
            // inherit the container's decryption (the /ObjStm is itself an
            // in-file object, decrypted by this same rule when it is fetched).
            if ($this->decryptor !== null && $entry->kind === XrefEntryKind::InFile) {
                $object = $this->decryptor->decrypt($object, $objectNumber, $entry->second);
            }
        } finally {
            unset($this->resolving[$objectNumber]);
        }
        return $this->cache[$objectNumber] = $object;
    }

    public function pageCount(): int
    {
        return count($this->pages ??= $this->collectPages());
    }

    /** @param int $pageNumber 1-based */
    public function page(int $pageNumber): ReadPage
    {
        $pages = $this->pages ??= $this->collectPages();
        if ($pageNumber < 1 || $pageNumber > count($pages)) {
            throw new PdfException("Page {$pageNumber} is out of range (document has " . count($pages) . ' pages)');
        }
        return $pages[$pageNumber - 1];
    }

    public function decodeStream(ReadStream $stream): string
    {
        return $this->decoder->decode($stream, $this->resolve(...));
    }

    /** @return list<ReadPage> */
    private function collectPages(): array
    {
        return (new PageTreeReader($this->resolve(...)))->collect($this->catalog());
    }

    private function parseAt(int $relativeOffset, int $expectedNumber): PdfObject
    {
        $absolute = $this->headerOffset + $relativeOffset;
        try {
            $object = $this->objectParser->parseIndirectObjectAt($absolute);
            if ($object->objectNumber === $expectedNumber) {
                return $object->payload();
            }
        } catch (PdfParseException) {
            // fall through to the recovery scan
        }
        return $this->recoverObjectNear($absolute, $expectedNumber);
    }

    /**
     * Leniency: the xref offset is wrong by a few bytes (common in files
     * touched by sloppy tools). Look for "N G obj" inside a window around
     * the recorded offset.
     */
    private function recoverObjectNear(int $absolute, int $expectedNumber): PdfObject
    {
        $windowStart = max($this->headerOffset, $absolute - self::OFFSET_RECOVERY_WINDOW);
        $window = substr($this->bytes, $windowStart, 2 * self::OFFSET_RECOVERY_WINDOW);
        if (preg_match('/(?<!\d)' . $expectedNumber . '\s+(\d+)\s+obj\b/', $window, $match, PREG_OFFSET_CAPTURE) === 1) {
            $object = $this->objectParser->parseIndirectObjectAt($windowStart + $match[0][1]);
            if ($object->objectNumber === $expectedNumber) {
                return $object->payload();
            }
        }
        throw new PdfParseException("Object {$expectedNumber} not found at xref offset " . ($absolute - $this->headerOffset) . ' (recovery scan failed)');
    }

    private function fromObjectStream(int $containerNumber, int $index): PdfObject
    {
        $reader = $this->objectStreams[$containerNumber] ??= $this->loadObjectStream($containerNumber);
        return $reader->objectAt($index);
    }

    private function loadObjectStream(int $containerNumber): ObjectStreamReader
    {
        $container = $this->object($containerNumber);
        if (!$container instanceof ReadStream) {
            throw new PdfParseException("Object {$containerNumber} is referenced as an object stream but is not a stream");
        }
        $count = DictReader::int($container->dict, 'N', $this->resolve(...));
        $first = DictReader::int($container->dict, 'First', $this->resolve(...));
        if ($count === null || $first === null) {
            throw new PdfParseException("Object stream {$containerNumber} is missing /N or /First");
        }
        return new ObjectStreamReader($this->decodeStream($container), $count, $first);
    }
}
