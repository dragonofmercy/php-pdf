<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Reads objects packed inside an object stream (/ObjStm, PDF 1.7 7.5.7).
 * Constructed with the ALREADY DECODED stream payload plus the /N and /First
 * dictionary values; the header pair table is parsed lazily once.
 *
 * @internal
 */
final class ObjectStreamReader
{
    /** @var ?list<array{0: int, 1: int}> [objectNumber, relativeOffset] per index */
    private ?array $pairs = null;

    public function __construct(
        private readonly string $data,
        private readonly int $count,
        private readonly int $first,
    ) {}

    public function objectAt(int $index): PdfObject
    {
        $pairs = $this->pairs();
        if (!isset($pairs[$index])) {
            throw new PdfParseException("Object stream has {$this->count} objects, index {$index} is out of range");
        }
        $lexer = new Lexer($this->data, $this->first + $pairs[$index][1]);
        return (new ObjectParser($lexer))->parseObject();
    }

    public function objectNumberAt(int $index): int
    {
        $pairs = $this->pairs();
        if (!isset($pairs[$index])) {
            throw new PdfParseException("Object stream has {$this->count} objects, index {$index} is out of range");
        }
        return $pairs[$index][0];
    }

    /** @return list<array{0: int, 1: int}> */
    private function pairs(): array
    {
        if ($this->pairs !== null) {
            return $this->pairs;
        }
        $lexer = new Lexer($this->data);
        $pairs = [];
        for ($i = 0; $i < $this->count; $i++) {
            $number = $lexer->next();
            $offset = $lexer->next();
            if ($number->type !== TokenType::Integer || $offset->type !== TokenType::Integer) {
                throw new PdfParseException("Malformed object stream header pair {$i}");
            }
            $pairs[] = [$number->toInt(), $offset->toInt()];
        }
        return $this->pairs = $pairs;
    }
}
