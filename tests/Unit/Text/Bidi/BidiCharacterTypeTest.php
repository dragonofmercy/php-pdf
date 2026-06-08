<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Text\Bidi;

use DragonOfMercy\PhpPdf\Text\Bidi\BidiCharacterType;
use PHPUnit\Framework\TestCase;

final class BidiCharacterTypeTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testClassOf(int $codepoint, string $expected): void
    {
        self::assertSame($expected, BidiCharacterType::of($codepoint));
    }

    /** @return iterable<string, array{int, string}> */
    public static function cases(): iterable
    {
        yield 'latin A'            => [0x0041, 'L'];
        yield 'latin z'            => [0x007A, 'L'];
        yield 'ascii digit 5'      => [0x0035, 'EN'];
        yield 'space'              => [0x0020, 'WS'];
        yield 'comma'              => [0x002C, 'CS'];
        yield 'plus'               => [0x002B, 'ES'];
        yield 'dollar'             => [0x0024, 'ET'];
        yield 'open paren'         => [0x0028, 'ON'];
        yield 'hebrew alef'        => [0x05D0, 'R'];
        yield 'hebrew point'       => [0x05B0, 'NSM'];
        yield 'arabic letter'      => [0x0627, 'AL'];
        yield 'arabic-indic digit' => [0x0660, 'AN'];
        yield 'paragraph sep LF'   => [0x000A, 'B'];
    }
}
