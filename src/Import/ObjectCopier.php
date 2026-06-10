<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Import;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;

/**
 * Deep-copies object subgraphs from a parsed source PDF into the destination
 * document's object space. Every distinct source object gets ONE new object
 * number from the destination allocator (an old->new map keeps shared
 * references shared and makes cycles safe); the copied indirect objects are
 * collected for emission. Scalars are immutable and pass through; stream
 * payloads keep their raw, still-encoded bytes.
 *
 * @internal
 */
final class ObjectCopier
{
    /** @var array<int, PdfReference> source object number => destination reference */
    private array $map = [];
    /** @var list<IndirectObject> */
    private array $collected = [];
    /** @var list<int> source object numbers whose copies are pending */
    private array $queue = [];

    public function __construct(
        private readonly PdfReader $source,
        private readonly PdfObjectAllocator $allocator,
    ) {}

    /**
     * Copies a value: references are renumbered (transitively copying the
     * objects they reach), containers are rebuilt, scalars pass through.
     */
    public function copy(PdfObject $value): PdfObject
    {
        $copied = $this->copyValue($value);
        $this->drainQueue();
        return $copied;
    }

    /**
     * All indirect objects copied so far, in allocation order.
     *
     * @return list<IndirectObject>
     */
    public function collectedObjects(): array
    {
        return $this->collected;
    }

    private function copyValue(PdfObject $value): PdfObject
    {
        if ($value instanceof PdfReference) {
            return $this->mapReference($value);
        }
        if ($value instanceof Dictionary) {
            return $this->copyDictionary($value);
        }
        if ($value instanceof PdfArray) {
            $elements = [];
            foreach ($value->elements() as $element) {
                $elements[] = $this->copyValue($element);
            }
            return PdfArray::of(...$elements);
        }
        if ($value instanceof ReadStream) {
            return new ReadStream($this->copyDictionary($value->dict), $value->rawData());
        }
        return $value; // Name, PdfNumber, PdfString, HexString, PdfBoolean, PdfNull: immutable
    }

    private function copyDictionary(Dictionary $dict): Dictionary
    {
        $copied = Dictionary::empty();
        foreach ($dict->entries() as [$name, $value]) {
            $copied = $copied->withEntry($name, $this->copyValue($value));
        }
        return $copied;
    }

    private function mapReference(PdfReference $reference): PdfReference
    {
        $sourceNumber = $reference->objectNumber;
        if (isset($this->map[$sourceNumber])) {
            return $this->map[$sourceNumber];
        }
        $destination = PdfReference::to($this->allocator->next(), 0);
        $this->map[$sourceNumber] = $destination;
        $this->queue[] = $sourceNumber;
        return $destination;
    }

    private function drainQueue(): void
    {
        while ($this->queue !== []) {
            $sourceNumber = array_shift($this->queue);
            $payload = $this->copyValue($this->source->object($sourceNumber));
            $this->collected[] = IndirectObject::of(
                $this->map[$sourceNumber]->objectNumber,
                0,
                $payload,
            );
        }
    }
}
