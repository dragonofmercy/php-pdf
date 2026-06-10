<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;

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
        throw new PdfParseException("No xref table at offset {$offset}"); // xref streams arrive in Task 8
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
