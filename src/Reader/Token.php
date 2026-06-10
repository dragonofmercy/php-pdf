<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

/**
 * One lexical token. For LiteralString the lexeme holds the DECODED bytes
 * (escapes resolved); for HexString the normalized hex digits (even length,
 * whitespace stripped); for numbers the raw spelling.
 *
 * @internal
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $offset,
    ) {}

    public function isKeyword(string $word): bool
    {
        return $this->type === TokenType::Keyword && $this->lexeme === $word;
    }

    public function toInt(): int
    {
        return (int) $this->lexeme;
    }
}
