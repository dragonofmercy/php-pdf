<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page\ContentStream;

/**
 * Abstracts the WinAnsi vs CIDFont/Type0 split for both measurement and
 * emission. A FontEngine is resolved once per Page::setFont() and cached;
 * Page and CellRenderer interact with the active font exclusively through
 * this seam.
 *
 * @internal
 */
interface FontEngine
{
    /** The Font instance this engine was resolved from. */
    public function font(): Font;

    /** Width of $text in points at $size. Empty string returns 0.0. */
    public function measure(string $text, float $size): float;

    /** Returns the show-text operator for $text in this engine's encoding. */
    public function encodeShowText(string $text): string;

    /** Appends a Tj show-text operator for the current line. */
    public function emitShowText(ContentStream $stream, string $text): void;

    /** Appends a T-quote (next-line + show) operator. */
    public function emitShowTextNextLine(ContentStream $stream, string $text): void;

    /**
     * Force-breaks a token that exceeds $innerW into chunks each fitting.
     * Chunks are returned in the encoding native to the engine (WinAnsi
     * bytes or raw UTF-8) so the caller can feed them straight back into
     * measure() / emitShowText().
     *
     * @return array{0: list<string>, 1: list<float>}
     */
    public function splitForceBreak(string $token, float $innerW, float $size): array;

    public function ascentAt(float $size): float;
    public function descentAt(float $size): float;
    public function capHeightAt(float $size): float;
    public function xHeightAt(float $size): float;

    /** Registers on the FontRegistry, returns the short name (e.g. 'F1'). */
    public function registerOn(FontRegistry $registry): string;

    /** Stable key for Page::$fontsUsed tracking. */
    public function usageKey(): string;
}
