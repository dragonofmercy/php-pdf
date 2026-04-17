<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Font;

use PhpPdf\Font\FontFamily;
use PHPUnit\Framework\TestCase;

final class FontFamilyTest extends TestCase
{
    public function testCasesExist(): void
    {
        $cases = FontFamily::cases();
        self::assertCount(3, $cases);
        self::assertContains(FontFamily::HELVETICA, $cases);
        self::assertContains(FontFamily::TIMES, $cases);
        self::assertContains(FontFamily::COURIER, $cases);
    }
}
