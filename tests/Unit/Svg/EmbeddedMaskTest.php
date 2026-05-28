<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\EmbeddedMask;
use PHPUnit\Framework\TestCase;

final class EmbeddedMaskTest extends TestCase
{
    public function testConstructionWithEmptyPatterns(): void
    {
        $bbox = [0.0, 0.0, 100.0, 100.0];
        $matrix = [1.0, 0.0, 0.0, 1.0, 10.0, 20.0];
        $extGStates = ['Gs0' => ['ca' => 0.5, 'CA' => 0.5, 'smaskEmbeddedIndex' => null]];
        $bytes = "1 0 0 1 0 0 cm\n";
        $emb = new EmbeddedMask($bbox, $matrix, $extGStates, [], $bytes);
        self::assertSame($bbox, $emb->bbox);
        self::assertSame($matrix, $emb->matrix);
        self::assertSame($extGStates, $emb->extGStates);
        self::assertSame([], $emb->patterns);
        self::assertSame($bytes, $emb->contentBytes);
    }

    public function testConstructionWithPatterns(): void
    {
        $bbox = [0.0, 0.0, 1.0, 1.0];
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $patterns = ['P0' => '<< /Type /Pattern /PatternType 2 ... >>'];
        $bytes = "/Pattern cs\n/P0 scn\n0 0 1 1 re f\n";
        $emb = new EmbeddedMask($bbox, $matrix, [], $patterns, $bytes);
        self::assertSame($patterns, $emb->patterns);
        self::assertSame([], $emb->extGStates);
    }
}
