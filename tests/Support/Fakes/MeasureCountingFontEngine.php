<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Support\Fakes;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Page\ContentStream;

/**
 * FontEngine decorator that counts measure() invocations. Used by tests
 * verifying call-count properties of the measurement pipeline.
 */
final class MeasureCountingFontEngine implements FontEngine
{
    public int $measureCalls = 0;

    public function __construct(private readonly FontEngine $inner) {}

    public function font(): Font
    {
        return $this->inner->font();
    }

    public function measure(string $text, float $size): float
    {
        $this->measureCalls++;
        return $this->inner->measure($text, $size);
    }

    public function emitShowText(ContentStream $stream, string $text): void
    {
        $this->inner->emitShowText($stream, $text);
    }

    public function emitShowTextNextLine(ContentStream $stream, string $text): void
    {
        $this->inner->emitShowTextNextLine($stream, $text);
    }

    /**
     * @return array{0: list<string>, 1: list<float>}
     */
    public function splitForceBreak(string $token, float $innerW, float $size): array
    {
        return $this->inner->splitForceBreak($token, $innerW, $size);
    }

    public function ascentAt(float $size): float
    {
        return $this->inner->ascentAt($size);
    }

    public function descentAt(float $size): float
    {
        return $this->inner->descentAt($size);
    }

    public function capHeightAt(float $size): float
    {
        return $this->inner->capHeightAt($size);
    }

    public function xHeightAt(float $size): float
    {
        return $this->inner->xHeightAt($size);
    }

    public function registerOn(FontRegistry $registry): string
    {
        return $this->inner->registerOn($registry);
    }

    public function usageKey(): string
    {
        return $this->inner->usageKey();
    }
}
