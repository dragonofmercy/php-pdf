<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

/**
 * Byte buffer for a page's PDF content stream. Accumulates operator bytes
 * as the user calls drawing methods. When emitted, prepends a Y-flip CTM
 * so that user coordinates are top-left / Y-down.
 *
 * An empty ContentStream emits an empty string, so pages with no drawing
 * produce no /Contents entry and remain byte-identical to pre-Phase-2a
 * fixtures.
 *
 * @internal
 */
final class ContentStream
{
    private string $userBytes = '';

    public function __construct(private readonly float $pageHeight) {}

    public function append(string $bytes): void
    {
        $this->userBytes .= $bytes;
    }

    public function beginMarkedContent(string $tag, int $mcid): void
    {
        $this->userBytes .= "/{$tag} <</MCID {$mcid}>> BDC\n";
    }

    public function endMarkedContent(): void
    {
        $this->userBytes .= "EMC\n";
    }

    public function beginArtifact(): void
    {
        $this->userBytes .= "/Artifact BDC\n";
    }

    public function endArtifact(): void
    {
        $this->userBytes .= "EMC\n";
    }

    public function isEmpty(): bool
    {
        return $this->userBytes === '';
    }

    public function bytes(): string
    {
        if ($this->userBytes === '') {
            return '';
        }
        return Operators::concatMatrix(1, 0, 0, -1, 0, $this->pageHeight) . $this->userBytes;
    }
}
