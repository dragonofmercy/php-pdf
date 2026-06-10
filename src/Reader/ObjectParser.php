<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Recursive-descent parser: tokens -> Writer\Object values. Detects
 * "int int R" references with a two-token lookahead (seek-back when the
 * pattern does not complete).
 *
 * @internal
 */
final readonly class ObjectParser
{
    /**
     * @param ?\Closure(PdfReference): PdfObject $lengthResolver resolves an
     *        indirect /Length while reading a stream payload (Task 4); when
     *        null or failing, the parser falls back to scanning for endstream
     */
    public function __construct(
        private Lexer $lexer,
        private ?\Closure $lengthResolver = null,
    ) {}

    public function parseObject(): PdfObject
    {
        return $this->parseFromToken($this->lexer->next());
    }

    /** Parses "N G obj <payload> endobj" starting exactly at $offset. */
    public function parseIndirectObjectAt(int $offset): IndirectObject
    {
        $this->lexer->seek($offset);
        $number = $this->expect(TokenType::Integer, 'object number');
        $generation = $this->expect(TokenType::Integer, 'generation number');
        $keyword = $this->lexer->next();
        if (!$keyword->isKeyword('obj')) {
            throw new PdfParseException("Expected 'obj' at offset {$keyword->offset}, got '{$keyword->lexeme}'");
        }
        $payload = $this->parseObject();
        if ($this->lexer->peek()->isKeyword('endobj')) {
            $this->lexer->next();
        }
        return IndirectObject::of($number->toInt(), $generation->toInt(), $payload);
    }

    private function expect(TokenType $type, string $what): Token
    {
        $token = $this->lexer->next();
        if ($token->type !== $type) {
            throw new PdfParseException("Expected {$what} at offset {$token->offset}, got '{$token->lexeme}'");
        }
        return $token;
    }

    private function parseFromToken(Token $token): PdfObject
    {
        switch ($token->type) {
            case TokenType::Integer:
                return $this->parseNumberOrReference($token);
            case TokenType::Real:
                return PdfNumber::ofFloat((float) $token->lexeme);
            case TokenType::Name:
                return Name::of($token->lexeme);
            case TokenType::LiteralString:
                return PdfString::of($token->lexeme);
            case TokenType::HexString:
                return HexString::of($token->lexeme);
            case TokenType::ArrayOpen:
                return $this->parseArray();
            case TokenType::DictOpen:
                return $this->parseDictionaryThenMaybeStream();
            case TokenType::Keyword:
                return match ($token->lexeme) {
                    'true' => PdfBoolean::true(),
                    'false' => PdfBoolean::false(),
                    'null' => PdfNull::instance(),
                    default => throw new PdfParseException("Unexpected keyword '{$token->lexeme}' at offset {$token->offset}"),
                };
            default:
                throw new PdfParseException("Unexpected token '{$token->lexeme}' at offset {$token->offset}");
        }
    }

    private function parseNumberOrReference(Token $first): PdfObject
    {
        $save = $this->lexer->position();
        if ($this->lexer->peek()->type === TokenType::Integer) {
            $second = $this->lexer->next();
            if ($this->lexer->peek()->isKeyword('R')) {
                $this->lexer->next();
                return PdfReference::to($first->toInt(), $second->toInt());
            }
            $this->lexer->seek($save);
        }
        return PdfNumber::ofInt($first->toInt());
    }

    private function parseArray(): PdfArray
    {
        $elements = [];
        while (true) {
            $token = $this->lexer->next();
            if ($token->type === TokenType::ArrayClose) {
                return PdfArray::of(...$elements);
            }
            if ($token->type === TokenType::EndOfInput) {
                throw new PdfParseException("Unterminated array at offset {$token->offset}");
            }
            $elements[] = $this->parseFromToken($token);
        }
    }

    private function parseDictionaryThenMaybeStream(): PdfObject
    {
        $dict = Dictionary::empty();
        while (true) {
            $token = $this->lexer->next();
            if ($token->type === TokenType::DictClose) {
                break;
            }
            if ($token->type !== TokenType::Name) {
                throw new PdfParseException("Expected a name as dictionary key at offset {$token->offset}, got '{$token->lexeme}'");
            }
            $dict = $dict->withEntry(Name::of($token->lexeme), $this->parseObject());
        }
        if (!$this->lexer->peek()->isKeyword('stream')) {
            return $dict;
        }
        return $this->parseStream($dict);
    }

    private function parseStream(Dictionary $dict): PdfObject
    {
        $streamKeyword = $this->lexer->next(); // consume 'stream'
        $dataStart = $this->lexer->beginStreamData();

        $data = null;
        $declared = $this->declaredLength($dict);
        if ($declared !== null && $declared >= 0) {
            $endstreamAt = $this->endstreamAt($dataStart + $declared);
            if ($endstreamAt !== null) {
                $data = $this->lexer->slice($dataStart, $declared);
                $this->lexer->seek($endstreamAt);
            }
        }
        if ($data === null) {
            // /Length missing, wrong, or unresolvable: scan for the marker
            $end = $this->lexer->indexOf('endstream', $dataStart);
            if ($end === null) {
                throw new PdfParseException("Stream starting at offset {$streamKeyword->offset} has no endstream marker");
            }
            $data = $this->trimOneTrailingEol($this->lexer->slice($dataStart, $end - $dataStart));
            $this->lexer->seek($end);
        }

        $keyword = $this->lexer->next();
        if (!$keyword->isKeyword('endstream')) {
            throw new PdfParseException("Expected 'endstream' at offset {$keyword->offset}, got '{$keyword->lexeme}'");
        }
        return new ReadStream($dict, $data);
    }

    /** Returns the offset of the endstream keyword found at/after $from across optional EOL/whitespace, or null. */
    private function endstreamAt(int $from): ?int
    {
        $pos = $from;
        $limit = min($this->lexer->length(), $from + 4);
        while ($pos < $limit) {
            $byte = $this->lexer->slice($pos, 1);
            if ($byte !== "\r" && $byte !== "\n" && $byte !== ' ' && $byte !== "\t") {
                break;
            }
            $pos++;
        }
        return $this->lexer->slice($pos, 9) === 'endstream' ? $pos : null;
    }

    private function declaredLength(Dictionary $dict): ?int
    {
        $length = $dict->get(Name::of('Length'));
        if ($length instanceof PdfReference && $this->lengthResolver !== null) {
            try {
                $length = ($this->lengthResolver)($length);
            } catch (PdfParseException) {
                return null; // fall back to endstream scan
            }
        }
        if (!$length instanceof PdfNumber) {
            return null;
        }
        $value = $length->value();
        return is_int($value) ? $value : (int) $value;
    }

    private function trimOneTrailingEol(string $data): string
    {
        if (str_ends_with($data, "\r\n")) {
            return substr($data, 0, -2);
        }
        if (str_ends_with($data, "\n") || str_ends_with($data, "\r")) {
            return substr($data, 0, -1);
        }
        return $data;
    }
}
