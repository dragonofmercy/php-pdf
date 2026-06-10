<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Lexer;
use DragonOfMercy\PhpPdf\Reader\TokenType;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    public function testLexesIntegersAndReals(): void
    {
        $lexer = new Lexer('123 +17 -98 0 34.5 -3.62 +123.6 4. -.002 0.0');
        $expected = [
            [TokenType::Integer, '123'],
            [TokenType::Integer, '+17'],
            [TokenType::Integer, '-98'],
            [TokenType::Integer, '0'],
            [TokenType::Real, '34.5'],
            [TokenType::Real, '-3.62'],
            [TokenType::Real, '+123.6'],
            [TokenType::Real, '4.'],
            [TokenType::Real, '-.002'],
            [TokenType::Real, '0.0'],
        ];
        foreach ($expected as [$type, $lexeme]) {
            $token = $lexer->next();
            self::assertSame($type, $token->type);
            self::assertSame($lexeme, $token->lexeme);
        }
        self::assertSame(TokenType::EndOfInput, $lexer->next()->type);
    }

    public function testLexesNamesWithHexEscapes(): void
    {
        $lexer = new Lexer('/Name1 /A;Name_With-Various***Chars? /paired#28#29parentheses /F#23 /');
        self::assertSame('Name1', $lexer->next()->lexeme);
        self::assertSame('A;Name_With-Various***Chars?', $lexer->next()->lexeme);
        self::assertSame('paired()parentheses', $lexer->next()->lexeme);
        self::assertSame('F#', $lexer->next()->lexeme);
        $empty = $lexer->next();
        self::assertSame(TokenType::Name, $empty->type);
        self::assertSame('', $empty->lexeme);
    }

    public function testLexesLiteralStringsWithEscapesAndNesting(): void
    {
        $lexer = new Lexer("(simple) (with (nested) parens) (a\\(b) (line\\nbreak) (octal \\101) (cont\\\n inued)");
        self::assertSame('simple', $lexer->next()->lexeme);
        self::assertSame('with (nested) parens', $lexer->next()->lexeme);
        self::assertSame('a(b', $lexer->next()->lexeme);
        self::assertSame("line\nbreak", $lexer->next()->lexeme);
        self::assertSame('octal A', $lexer->next()->lexeme);
        self::assertSame('cont inued', $lexer->next()->lexeme);
    }

    public function testLiteralStringTranslatesRawEolToLf(): void
    {
        $lexer = new Lexer("(a\r\nb\rc)");
        self::assertSame("a\nb\nc", $lexer->next()->lexeme);
    }

    public function testLexesHexStrings(): void
    {
        $lexer = new Lexer('<48656C6C6F> <48 65 6C> <901FA>');
        self::assertSame('48656C6C6F', $lexer->next()->lexeme);
        self::assertSame('48656C', $lexer->next()->lexeme);
        self::assertSame('901FA0', $lexer->next()->lexeme); // odd length padded with 0
    }

    public function testLexesStructuralTokensAndKeywords(): void
    {
        $lexer = new Lexer("<< /Type /Page >> [ 1 2 ] true false null 12 0 obj endobj stream endstream R");
        $types = [
            TokenType::DictOpen, TokenType::Name, TokenType::Name, TokenType::DictClose,
            TokenType::ArrayOpen, TokenType::Integer, TokenType::Integer, TokenType::ArrayClose,
            TokenType::Keyword, TokenType::Keyword, TokenType::Keyword,
            TokenType::Integer, TokenType::Integer, TokenType::Keyword, TokenType::Keyword,
            TokenType::Keyword, TokenType::Keyword, TokenType::Keyword,
        ];
        foreach ($types as $type) {
            self::assertSame($type, $lexer->next()->type);
        }
    }

    public function testSkipsCommentsAndAllWhitespaceKinds(): void
    {
        $lexer = new Lexer("% a comment\n\x00\t\x0C 42 % trailing\r\n7");
        self::assertSame('42', $lexer->next()->lexeme);
        self::assertSame('7', $lexer->next()->lexeme);
        self::assertSame(TokenType::EndOfInput, $lexer->next()->type);
    }

    public function testPeekDoesNotConsumeAndPositionSeekRoundTrips(): void
    {
        $lexer = new Lexer('1 2 R');
        self::assertSame('1', $lexer->peek()->lexeme);
        self::assertSame('1', $lexer->next()->lexeme);
        $save = $lexer->position();
        self::assertSame('2', $lexer->next()->lexeme);
        self::assertSame('R', $lexer->next()->lexeme);
        $lexer->seek($save);
        self::assertSame('2', $lexer->next()->lexeme);
    }

    public function testTokenOffsetPointsAtTokenStart(): void
    {
        $lexer = new Lexer('   42');
        self::assertSame(3, $lexer->next()->offset);
    }

    public function testThrowsOnUnexpectedByteWithOffset(): void
    {
        $lexer = new Lexer('}');
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('offset 0');
        $lexer->next();
    }

    public function testBeginStreamDataSkipsEol(): void
    {
        $bytes = "stream\r\nDATA";
        $lexer = new Lexer($bytes);
        self::assertSame('stream', $lexer->next()->lexeme);
        self::assertSame(8, $lexer->beginStreamData());
        self::assertSame('DATA', $lexer->slice(8, 4));
    }
}
