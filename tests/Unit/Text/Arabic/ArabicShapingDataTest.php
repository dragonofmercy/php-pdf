<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Text\Arabic;

use DragonOfMercy\PhpPdf\Text\Arabic\ArabicShapingData;
use PHPUnit\Framework\TestCase;

final class ArabicShapingDataTest extends TestCase
{
    public function testFormsMapBehToItsFourPresentationForms(): void
    {
        // [isolated, initial, medial, final]
        self::assertSame([0xFE8F, 0xFE91, 0xFE92, 0xFE90], ArabicShapingData::FORMS[0x0628]);
    }

    public function testRightJoiningRehHasOnlyIsolatedAndFinal(): void
    {
        self::assertSame([0xFEAD, 0, 0, 0xFEAE], ArabicShapingData::FORMS[0x0631]);
    }

    public function testJoiningTypes(): void
    {
        self::assertSame('D', ArabicShapingData::JOINING_TYPES[0x0644]); // lam
        self::assertSame('R', ArabicShapingData::JOINING_TYPES[0x0627]); // alef
        self::assertSame('C', ArabicShapingData::JOINING_TYPES[0x0640]); // tatweel
        self::assertSame('T', ArabicShapingData::JOINING_TYPES[0x064E]); // fatha (Mn default)
    }

    public function testLamAlefLigatures(): void
    {
        self::assertSame([0xFEFB, 0xFEFC], ArabicShapingData::LAM_ALEF[0x0627]); // plain
        self::assertSame([0xFEF5, 0xFEF6], ArabicShapingData::LAM_ALEF[0x0622]); // madda
        self::assertSame([0xFEF7, 0xFEF8], ArabicShapingData::LAM_ALEF[0x0623]); // hamza above
        self::assertSame([0xFEF9, 0xFEFA], ArabicShapingData::LAM_ALEF[0x0625]); // hamza below
    }
}
