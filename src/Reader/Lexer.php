<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;

/**
 * Tokenizer for PDF syntax (PDF 1.7 7.2). Operates on the raw byte string
 * with a movable cursor; also exposes raw byte access used to read stream
 * payloads, which are not tokenized.
 *
 * @internal
 */
final class Lexer
{
    private const string WHITESPACE = "\x00\t\n\x0C\r ";
    private const string DELIMITERS = '()<>[]{}/%';

    private readonly int $length;
    private int $pos;
    private ?Token $peeked = null;
    private int $peekedStart = 0;

    public function __construct(private readonly string $bytes, int $offset = 0)
    {
        $this->length = strlen($bytes);
        $this->pos = $offset;
    }

    /** Offset from which next() would lex its token (stable across peek()). */
    public function position(): int
    {
        return $this->peeked !== null ? $this->peekedStart : $this->pos;
    }

    public function seek(int $offset): void
    {
        $this->pos = $offset;
        $this->peeked = null;
    }

    public function peek(): Token
    {
        if ($this->peeked === null) {
            $this->peekedStart = $this->pos;
            $this->peeked = $this->lex();
        }
        return $this->peeked;
    }

    public function next(): Token
    {
        if ($this->peeked !== null) {
            $token = $this->peeked;
            $this->peeked = null;
            return $token;
        }
        return $this->lex();
    }

    /**
     * Call immediately after consuming the `stream` keyword: skips the
     * single EOL that separates the keyword from the data (CRLF or LF per
     * spec; a lone CR is tolerated) and returns the data start offset.
     */
    public function beginStreamData(): int
    {
        $this->peeked = null; // a pending peek would desynchronize the cursor
        $byte = $this->bytes[$this->pos] ?? '';
        if ($byte === "\r") {
            $this->pos++;
            if (($this->bytes[$this->pos] ?? '') === "\n") {
                $this->pos++;
            }
        } elseif ($byte === "\n") {
            $this->pos++;
        }
        return $this->pos;
    }

    public function slice(int $start, int $length): string
    {
        return substr($this->bytes, $start, max(0, $length));
    }

    public function indexOf(string $needle, int $from): ?int
    {
        $found = strpos($this->bytes, $needle, $from);
        return $found === false ? null : $found;
    }

    public function length(): int
    {
        return $this->length;
    }

    /** Single byte at $offset without allocation churn; '' past the end. */
    public function byteAt(int $offset): string
    {
        return $this->bytes[$offset] ?? '';
    }

    private function lex(): Token
    {
        $this->skipWhitespaceAndComments();
        if ($this->pos >= $this->length) {
            return new Token(TokenType::EndOfInput, '', $this->pos);
        }
        $start = $this->pos;
        $byte = $this->bytes[$this->pos];
        if ($byte === '<') {
            if (($this->bytes[$this->pos + 1] ?? '') === '<') {
                $this->pos += 2;
                return new Token(TokenType::DictOpen, '<<', $start);
            }
            return $this->lexHexString($start);
        }
        if ($byte === '>') {
            if (($this->bytes[$this->pos + 1] ?? '') === '>') {
                $this->pos += 2;
                return new Token(TokenType::DictClose, '>>', $start);
            }
            throw new PdfParseException("Unexpected '>' at offset {$start}");
        }
        if ($byte === '[') {
            $this->pos++;
            return new Token(TokenType::ArrayOpen, '[', $start);
        }
        if ($byte === ']') {
            $this->pos++;
            return new Token(TokenType::ArrayClose, ']', $start);
        }
        if ($byte === '/') {
            return $this->lexName($start);
        }
        if ($byte === '(') {
            return $this->lexLiteralString($start);
        }
        if ($byte === '+' || $byte === '-' || $byte === '.' || ctype_digit($byte)) {
            return $this->lexNumber($start);
        }
        if (ctype_alpha($byte)) {
            return $this->lexKeyword($start);
        }
        throw new PdfParseException(sprintf('Unexpected byte 0x%02X at offset %d', ord($byte), $start));
    }

    private function skipWhitespaceAndComments(): void
    {
        $length = $this->length;
        while ($this->pos < $length) {
            $byte = $this->bytes[$this->pos];
            if (str_contains(self::WHITESPACE, $byte)) {
                $this->pos++;
                continue;
            }
            if ($byte === '%') {
                while ($this->pos < $length && $this->bytes[$this->pos] !== "\r" && $this->bytes[$this->pos] !== "\n") {
                    $this->pos++;
                }
                continue;
            }
            break;
        }
    }

    private function lexName(int $start): Token
    {
        $this->pos++; // consume '/'
        $value = '';
        $length = $this->length;
        while ($this->pos < $length) {
            $byte = $this->bytes[$this->pos];
            if (str_contains(self::WHITESPACE, $byte) || str_contains(self::DELIMITERS, $byte)) {
                break;
            }
            if ($byte === '#' && ctype_xdigit($this->bytes[$this->pos + 1] ?? '') && ctype_xdigit($this->bytes[$this->pos + 2] ?? '')) {
                $value .= chr(((int) hexdec(substr($this->bytes, $this->pos + 1, 2))) & 0xFF);
                $this->pos += 3;
                continue;
            }
            $value .= $byte;
            $this->pos++;
        }
        return new Token(TokenType::Name, $value, $start);
    }

    private function lexLiteralString(int $start): Token
    {
        $this->pos++; // consume '('
        $value = '';
        $depth = 1;
        $length = $this->length;
        while ($this->pos < $length) {
            $byte = $this->bytes[$this->pos];
            if ($byte === '\\') {
                $this->pos++;
                $value .= $this->decodeStringEscape();
                continue;
            }
            if ($byte === '(') {
                $depth++;
                $value .= $byte;
                $this->pos++;
                continue;
            }
            if ($byte === ')') {
                $depth--;
                $this->pos++;
                if ($depth === 0) {
                    return new Token(TokenType::LiteralString, $value, $start);
                }
                $value .= $byte;
                continue;
            }
            if ($byte === "\r") {
                // raw EOL inside a string is recorded as LF (7.3.4.2)
                $value .= "\n";
                $this->pos++;
                if (($this->bytes[$this->pos] ?? '') === "\n") {
                    $this->pos++;
                }
                continue;
            }
            $value .= $byte;
            $this->pos++;
        }
        throw new PdfParseException("Unterminated literal string starting at offset {$start}");
    }

    private function decodeStringEscape(): string
    {
        $byte = $this->bytes[$this->pos] ?? '';
        if ($byte === '') {
            return '';
        }
        $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];
        if (isset($simple[$byte])) {
            $this->pos++;
            return $simple[$byte];
        }
        if ($byte === "\r" || $byte === "\n") {
            // backslash-EOL: line continuation, contributes nothing
            $this->pos++;
            if ($byte === "\r" && ($this->bytes[$this->pos] ?? '') === "\n") {
                $this->pos++;
            }
            return '';
        }
        if ($byte >= '0' && $byte <= '7') {
            $octal = '';
            for ($i = 0; $i < 3; $i++) {
                $digit = $this->bytes[$this->pos] ?? '';
                if ($digit < '0' || $digit > '7') {
                    break;
                }
                $octal .= $digit;
                $this->pos++;
            }
            return chr(((int) octdec($octal)) & 0xFF);
        }
        // unknown escape: backslash is dropped, byte kept (7.3.4.2)
        $this->pos++;
        return $byte;
    }

    private function lexHexString(int $start): Token
    {
        $this->pos++; // consume '<'
        $hex = '';
        $length = $this->length;
        while ($this->pos < $length) {
            $byte = $this->bytes[$this->pos];
            if ($byte === '>') {
                $this->pos++;
                if (strlen($hex) % 2 === 1) {
                    $hex .= '0';
                }
                return new Token(TokenType::HexString, strtoupper($hex), $start);
            }
            if (ctype_xdigit($byte)) {
                $hex .= $byte;
            } elseif (!str_contains(self::WHITESPACE, $byte)) {
                throw new PdfParseException(sprintf('Invalid byte 0x%02X in hex string at offset %d', ord($byte), $this->pos));
            }
            $this->pos++;
        }
        throw new PdfParseException("Unterminated hex string starting at offset {$start}");
    }

    private function lexNumber(int $start): Token
    {
        $length = $this->length;
        $sawDot = false;
        $sawDigit = false;
        $lexeme = '';
        if ($this->bytes[$this->pos] === '+' || $this->bytes[$this->pos] === '-') {
            $lexeme .= $this->bytes[$this->pos];
            $this->pos++;
        }
        while ($this->pos < $length) {
            $byte = $this->bytes[$this->pos];
            if (ctype_digit($byte)) {
                $sawDigit = true;
            } elseif ($byte === '.' && !$sawDot) {
                $sawDot = true;
            } else {
                break;
            }
            $lexeme .= $byte;
            $this->pos++;
        }
        if (!$sawDigit && !$sawDot) {
            throw new PdfParseException("Malformed number at offset {$start}");
        }
        return new Token($sawDot ? TokenType::Real : TokenType::Integer, $lexeme, $start);
    }

    private function lexKeyword(int $start): Token
    {
        $word = '';
        $length = $this->length;
        while ($this->pos < $length && ctype_alpha($this->bytes[$this->pos])) {
            $word .= $this->bytes[$this->pos];
            $this->pos++;
        }
        return new Token(TokenType::Keyword, $word, $start);
    }
}
