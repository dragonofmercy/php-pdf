<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Filter\StreamDecoder;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
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
    /** @var list<float> */
    private const array DEFAULT_MEDIA_BOX = [0.0, 0.0, 612.0, 792.0]; // US Letter, lenient fallback

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
    /** @var ?list<ReadPage> */
    private ?array $pages = null;

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
        $rootValue = $this->catalog()->get(Name::of('Pages'));
        if ($rootValue === null) {
            throw new PdfParseException('The catalog has no /Pages entry');
        }
        $root = $this->resolve($rootValue);
        if (!$root instanceof Dictionary) {
            throw new PdfParseException('/Pages does not resolve to a dictionary');
        }

        $pages = [];
        /** @var array{mediaBox: ?list<float>, cropBox: ?list<float>, rotate: ?int, resources: ?Dictionary} $inherited */
        $inherited = ['mediaBox' => null, 'cropBox' => null, 'rotate' => null, 'resources' => null];
        $visited = [];
        $this->walkPagesNode($root, $inherited, $visited, $pages, 0);
        return $pages;
    }

    /**
     * @param array{mediaBox: ?list<float>, cropBox: ?list<float>, rotate: ?int, resources: ?Dictionary} $inherited passed by value: each subtree gets its own copy
     * @param array<int, true> $visited keyed by kid object number
     * @param list<ReadPage> $pages
     */
    private function walkPagesNode(Dictionary $node, array $inherited, array &$visited, array &$pages, int $depth): void
    {
        if ($depth > 64) {
            throw new PdfParseException('Pages tree deeper than 64 levels (cycle suspected)');
        }
        $inherited['mediaBox'] = $this->boxEntry($node, 'MediaBox') ?? $inherited['mediaBox'];
        $inherited['cropBox'] = $this->boxEntry($node, 'CropBox') ?? $inherited['cropBox'];
        $inherited['rotate'] = DictReader::int($node, 'Rotate', $this->resolve(...)) ?? $inherited['rotate'];
        $inherited['resources'] = DictReader::dictionary($node, 'Resources', $this->resolve(...)) ?? $inherited['resources'];

        $kids = $this->resolve($node->get(Name::of('Kids')) ?? PdfNull::instance());
        $isPagesNode = DictReader::name($node, 'Type') === 'Pages' || $kids instanceof PdfArray;
        if (!$isPagesNode) {
            $pages[] = $this->makePage($node, $inherited);
            return;
        }
        if (!$kids instanceof PdfArray) {
            return; // a /Pages node with no kids: empty subtree
        }
        foreach ($kids->elements() as $kid) {
            if ($kid instanceof PdfReference) {
                if (isset($visited[$kid->objectNumber])) {
                    throw new PdfParseException("Pages tree cycle through object {$kid->objectNumber}");
                }
                $visited[$kid->objectNumber] = true;
            }
            $kidDict = $this->resolve($kid);
            if ($kidDict instanceof Dictionary) {
                $this->walkPagesNode($kidDict, $inherited, $visited, $pages, $depth + 1);
            }
        }
    }

    /**
     * @param array{mediaBox: ?list<float>, cropBox: ?list<float>, rotate: ?int, resources: ?Dictionary} $inherited
     */
    private function makePage(Dictionary $dict, array $inherited): ReadPage
    {
        $rotate = $inherited['rotate'] ?? 0;
        $rotate = (($rotate % 360) + 360) % 360;
        if ($rotate % 90 !== 0) {
            $rotate = 0;
        }
        return new ReadPage(
            dict: $dict,
            mediaBox: $inherited['mediaBox'] ?? self::DEFAULT_MEDIA_BOX,
            cropBox: $inherited['cropBox'],
            rotate: $rotate,
            resources: $inherited['resources'],
            contents: $this->contentsRefs($dict),
        );
    }

    /** @return ?list<float> corner-normalized [llx, lly, urx, ury] */
    private function boxEntry(Dictionary $dict, string $key): ?array
    {
        $value = $dict->get(Name::of($key));
        if ($value === null) {
            return null;
        }
        $value = $this->resolve($value);
        if (!$value instanceof PdfArray || count($value->elements()) !== 4) {
            return null;
        }
        $numbers = [];
        foreach ($value->elements() as $element) {
            $element = $this->resolve($element);
            if (!$element instanceof PdfNumber) {
                return null;
            }
            $numbers[] = (float) $element->value();
        }
        return [
            min($numbers[0], $numbers[2]),
            min($numbers[1], $numbers[3]),
            max($numbers[0], $numbers[2]),
            max($numbers[1], $numbers[3]),
        ];
    }

    /** @return list<PdfReference> */
    private function contentsRefs(Dictionary $page): array
    {
        $contents = $page->get(Name::of('Contents'));
        if ($contents === null) {
            return [];
        }
        if ($contents instanceof PdfReference) {
            $resolved = $this->resolve($contents);
            if ($resolved instanceof PdfArray) {
                $contents = $resolved;       // /Contents was a ref to an array
            } else {
                return $resolved instanceof ReadStream ? [$contents] : [];
            }
        }
        if (!$contents instanceof PdfArray) {
            return [];
        }
        $refs = [];
        foreach ($contents->elements() as $element) {
            if ($element instanceof PdfReference) {
                $refs[] = $element;
            }
        }
        return $refs;
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
