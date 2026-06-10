<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Filter\StreamDecoder;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Locates and parses the cross-reference data of a PDF file: classic xref
 * tables and (Task 8) cross-reference streams, following /Prev chains across
 * incremental revisions (Task 9).
 *
 * @internal
 */
final readonly class XrefReader
{
    private const int STARTXREF_SEARCH_WINDOW = 2048;

    public function __construct(
        private string $bytes,
        private int $headerOffset,
    ) {}

    public function read(): XrefData
    {
        return $this->readSection($this->findStartxref());
    }

    private function findStartxref(): int
    {
        $windowStart = max(0, strlen($this->bytes) - self::STARTXREF_SEARCH_WINDOW);
        $tail = substr($this->bytes, $windowStart);
        $position = strrpos($tail, 'startxref');
        if ($position === false) {
            throw new PdfParseException(sprintf(
                'startxref not found in the last %d bytes of the file',
                self::STARTXREF_SEARCH_WINDOW,
            ));
        }
        $lexer = new Lexer($this->bytes, $windowStart + $position + strlen('startxref'));
        $token = $lexer->next();
        if ($token->type !== TokenType::Integer) {
            throw new PdfParseException("Expected an integer after startxref at offset {$token->offset}, got '{$token->lexeme}'");
        }
        return $token->toInt();
    }

    private function readSection(int $offset): XrefData
    {
        $absolute = $this->headerOffset + $offset;
        if ($absolute < 0 || $absolute >= strlen($this->bytes)) {
            throw new PdfParseException("xref offset {$offset} is outside the file");
        }
        $lexer = new Lexer($this->bytes, $absolute);
        if ($lexer->peek()->isKeyword('xref')) {
            return $this->readClassicSection($lexer);
        }
        return $this->readStreamSection($lexer, $offset);
    }

    private function readStreamSection(Lexer $lexer, int $offset): XrefData
    {
        $object = (new ObjectParser($lexer))->parseIndirectObjectAt($lexer->position());
        $payload = $object->payload();
        if (!$payload instanceof ReadStream) {
            throw new PdfParseException("Expected an xref stream object at offset {$offset}");
        }
        $dict = $payload->dict;
        $identity = static fn (PdfObject $o): PdfObject => $o; // xref stream dict entries are direct per spec
        $widths = DictReader::intList($dict, 'W', $identity);
        if ($widths === null || count($widths) < 3) {
            throw new PdfParseException("xref stream at offset {$offset} has a missing or malformed /W entry");
        }
        $size = DictReader::int($dict, 'Size', $identity);
        if ($size === null) {
            throw new PdfParseException("xref stream at offset {$offset} has no /Size entry");
        }
        $index = DictReader::intList($dict, 'Index', $identity) ?? [0, $size];
        if (count($index) % 2 !== 0) {
            throw new PdfParseException("xref stream at offset {$offset} has an odd /Index entry");
        }

        $data = (new StreamDecoder())->decode($payload, $identity);
        $rowLength = $widths[0] + $widths[1] + $widths[2];
        if ($rowLength <= 0) {
            throw new PdfParseException("xref stream at offset {$offset} has zero-width rows");
        }

        $entries = [];
        $position = 0;
        $available = strlen($data);
        $indexCount = count($index);
        for ($pair = 0; $pair < $indexCount; $pair += 2) {
            $start = $index[$pair];
            $count = $index[$pair + 1];
            for ($i = 0; $i < $count; $i++) {
                if ($position + $rowLength > $available) {
                    throw new PdfParseException(sprintf(
                        'Truncated xref stream at offset %d: needed %d rows of %d bytes',
                        $offset,
                        $count,
                        $rowLength,
                    ));
                }
                $type = $widths[0] === 0 ? 1 : $this->bigEndian($data, $position, $widths[0]);
                $field2 = $this->bigEndian($data, $position + $widths[0], $widths[1]);
                $field3 = $this->bigEndian($data, $position + $widths[0] + $widths[1], $widths[2]);
                $position += $rowLength;
                $objectNumber = $start + $i;
                $entry = match ($type) {
                    0 => XrefEntry::free(),
                    1 => XrefEntry::inFile($field2, $field3),
                    2 => XrefEntry::inObjectStream($field2, $field3),
                    default => null, // unknown types must be treated as absent (7.5.8.3)
                };
                if ($entry !== null) {
                    $entries[$objectNumber] ??= $entry;
                }
            }
        }
        return new XrefData($entries, $dict);
    }

    private function bigEndian(string $data, int $offset, int $width): int
    {
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($data[$offset + $i]);
        }
        return $value;
    }

    private function readClassicSection(Lexer $lexer): XrefData
    {
        $lexer->next(); // consume 'xref'
        $entries = [];
        while (true) {
            $token = $lexer->next();
            if ($token->isKeyword('trailer')) {
                break;
            }
            if ($token->type !== TokenType::Integer) {
                throw new PdfParseException("Expected a subsection start or 'trailer' at offset {$token->offset}, got '{$token->lexeme}'");
            }
            $start = $token->toInt();
            $countToken = $lexer->next();
            if ($countToken->type !== TokenType::Integer) {
                throw new PdfParseException("Expected a subsection count at offset {$countToken->offset}, got '{$countToken->lexeme}'");
            }
            $count = $countToken->toInt();
            for ($i = 0; $i < $count; $i++) {
                $field1 = $lexer->next();
                $field2 = $lexer->next();
                $kind = $lexer->next();
                if ($field1->type !== TokenType::Integer || $field2->type !== TokenType::Integer) {
                    throw new PdfParseException("Malformed xref entry at offset {$field1->offset}");
                }
                $objectNumber = $start + $i;
                if ($kind->isKeyword('n')) {
                    $entries[$objectNumber] ??= XrefEntry::inFile($field1->toInt(), $field2->toInt());
                } elseif ($kind->isKeyword('f')) {
                    $entries[$objectNumber] ??= XrefEntry::free();
                } else {
                    throw new PdfParseException("Expected 'n' or 'f' in xref entry at offset {$kind->offset}, got '{$kind->lexeme}'");
                }
            }
        }
        $trailer = (new ObjectParser($lexer))->parseObject();
        if (!$trailer instanceof Dictionary) {
            throw new PdfParseException('The trailer is not a dictionary');
        }
        return new XrefData($entries, $trailer);
    }
}
