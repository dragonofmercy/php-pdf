<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\PdfA\OutputIntent;
use PHPUnit\Framework\TestCase;

final class OutputIntentTest extends TestCase
{
    public function testBuildsIntentDictAndIccStream(): void
    {
        $icc = (string) file_get_contents(__DIR__ . '/../../../resources/icc/sRGB.icc');
        [$intent, $profile] = (new OutputIntent())->build(intentObjectNumber: 50, profileObjectNumber: 51, iccBytes: $icc);

        self::assertSame(50, $intent->objectNumber);
        self::assertSame(51, $profile->objectNumber);

        $intentBytes = $intent->toBytes();
        self::assertStringContainsString('/Type /OutputIntent', $intentBytes);
        self::assertStringContainsString('/S /GTS_PDFA1', $intentBytes);
        self::assertStringContainsString('(sRGB IEC61966-2.1)', $intentBytes);
        self::assertStringContainsString('/DestOutputProfile 51 0 R', $intentBytes);

        $profileBytes = $profile->toBytes();
        self::assertStringContainsString('/N 3', $profileBytes);
        self::assertStringContainsString('/Filter /FlateDecode', $profileBytes);
        self::assertStringContainsString('stream', $profileBytes);
        $start = strpos($profileBytes, "stream\n");
        self::assertIsInt($start);
        $end = strpos($profileBytes, "\nendstream");
        self::assertIsInt($end);
        $compressed = substr($profileBytes, $start + 7, $end - ($start + 7));
        self::assertSame($icc, (string) gzuncompress($compressed));
    }
}
