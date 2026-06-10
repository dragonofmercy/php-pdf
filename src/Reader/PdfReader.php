<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Filter\StreamDecoder;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Reads an existing (non-encrypted) PDF file: cross-reference data, lazy
 * object resolution with caching, and the page tree. This is the foundation
 * for template import and incremental modification of existing files.
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
    /** @var array<int, PdfObject> */
    private array $cache = [];
    /** @var array<int, true> */
    private array $resolving = [];
    /** @var array<int, ObjectStreamReader> */
    private array $objectStreams = [];

    private function __construct(private readonly string $bytes)
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
        if ($this->xref->trailer->get(Name::of('Encrypt')) !== null) {
            throw new PdfException('Encrypted PDF input is not supported (the file has an /Encrypt dictionary); decrypt it first');
        }
    }

    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    public static function fromFile(string $path): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PdfException("Cannot read PDF file: {$path}");
        }
        return new self($bytes);
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
        } finally {
            unset($this->resolving[$objectNumber]);
        }
        return $this->cache[$objectNumber] = $object;
    }

    public function decodeStream(ReadStream $stream): string
    {
        return $this->decoder->decode($stream, $this->resolve(...));
    }

    private function parseAt(int $relativeOffset, int $expectedNumber): PdfObject
    {
        $absolute = $this->headerOffset + $relativeOffset;
        $parser = new ObjectParser(new Lexer($this->bytes), fn (PdfReference $ref): PdfObject => $this->resolve($ref));
        try {
            $object = $parser->parseIndirectObjectAt($absolute);
            if ($object->objectNumber === $expectedNumber) {
                return $object->payload();
            }
        } catch (PdfParseException) {
            // fall through to the recovery scan
        }
        return $this->recoverObjectNear($absolute, $expectedNumber, $parser);
    }

    /**
     * Leniency: the xref offset is wrong by a few bytes (common in files
     * touched by sloppy tools). Look for "N G obj" inside a window around
     * the recorded offset.
     */
    private function recoverObjectNear(int $absolute, int $expectedNumber, ObjectParser $parser): PdfObject
    {
        $windowStart = max($this->headerOffset, $absolute - self::OFFSET_RECOVERY_WINDOW);
        $window = substr($this->bytes, $windowStart, 2 * self::OFFSET_RECOVERY_WINDOW);
        if (preg_match('/(?<!\d)' . $expectedNumber . '\s+(\d+)\s+obj\b/', $window, $match, PREG_OFFSET_CAPTURE) === 1) {
            $object = $parser->parseIndirectObjectAt($windowStart + $match[0][1]);
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
