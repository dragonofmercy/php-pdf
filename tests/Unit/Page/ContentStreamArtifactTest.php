<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class ContentStreamArtifactTest extends TestCase
{
    public function testArtifactBracketsEmitBdcAndEmc(): void
    {
        $stream = new ContentStream(100.0);
        $stream->beginArtifact();
        $stream->append("0 0 10 10 re f\n");
        $stream->endArtifact();

        $bytes = $stream->bytes();
        self::assertStringContainsString("/Artifact BMC\n0 0 10 10 re f\nEMC\n", $bytes);
        self::assertGreaterThan(strpos($bytes, 'BDC'), strpos($bytes, 'EMC'));
    }
}
