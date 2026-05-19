<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\SubsetTag;
use PHPUnit\Framework\TestCase;

final class SubsetTagTest extends TestCase
{
    public function testTagIsExactlySixUppercaseLetters(): void
    {
        $tag = SubsetTag::derive('FreeSans', [0, 1, 36, 37]);
        self::assertMatchesRegularExpression('/^[A-Z]{6}$/', $tag);
    }

    public function testTagIsDeterministicForSameInput(): void
    {
        self::assertSame(
            SubsetTag::derive('FreeSans', [0, 1, 36, 37]),
            SubsetTag::derive('FreeSans', [0, 1, 36, 37]),
        );
    }

    public function testDifferentGidSetsGiveDifferentTags(): void
    {
        self::assertNotSame(
            SubsetTag::derive('FreeSans', [0, 1, 36]),
            SubsetTag::derive('FreeSans', [0, 1, 36, 37]),
        );
    }

    public function testDifferentPsNameGivesDifferentTag(): void
    {
        self::assertNotSame(
            SubsetTag::derive('FreeSans', [0, 1]),
            SubsetTag::derive('FreeSansBold', [0, 1]),
        );
    }
}
