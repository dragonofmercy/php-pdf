<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use PHPUnit\Framework\TestCase;

final class SrgbProfileTest extends TestCase
{
    private const string PROFILE = __DIR__ . '/../../../resources/icc/sRGB.icc';

    public function testProfileExistsAndIsValidRgbIcc(): void
    {
        self::assertFileExists(self::PROFILE);
        $data = (string) file_get_contents(self::PROFILE);
        self::assertSame('acsp', substr($data, 36, 4), 'not a valid ICC profile (missing acsp signature)');
        self::assertSame('RGB ', substr($data, 16, 4), 'profile data colour space must be RGB');
    }
}
