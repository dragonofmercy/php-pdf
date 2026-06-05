<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class ContentStreamMarkedContentTest extends TestCase
{
    public function testMarkedContentOperators(): void
    {
        $cs = new ContentStream(100.0);
        $cs->beginMarkedContent('P', 0);
        $cs->append("BT ET\n");
        $cs->endMarkedContent();

        $bytes = $cs->bytes();
        self::assertStringContainsString("/P <</MCID 0>> BDC\n", $bytes);
        self::assertStringContainsString("EMC\n", $bytes);
        // EMC must come after the BDC.
        self::assertGreaterThan(strpos($bytes, 'BDC'), strpos($bytes, 'EMC'));
    }

    public function testEmptyStreamUnaffectedWhenNoMarkedContent(): void
    {
        $cs = new ContentStream(100.0);
        self::assertSame('', $cs->bytes());
    }
}
