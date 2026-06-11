<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

final readonly class DefaultAppearance
{
    public function __construct(
        public string $fontAlias,
        public float $size,
        public string $colorOps,
    ) {}

    public static function parse(string $da): self
    {
        $raw = preg_split('/\s+/', trim($da), -1, PREG_SPLIT_NO_EMPTY);

        // preg_split returns false only on a pattern error, which cannot happen
        // with our literal pattern; but we must narrow the type for PHPStan.
        if ($raw === false || $raw === []) {
            return new self('Helv', 0.0, '0 g');
        }

        /** @var non-empty-list<string> $tokens */
        $tokens = $raw;

        // Find the last 'Tf' operator index.
        $tfIndex = null;
        $count = count($tokens);
        for ($i = $count - 1; $i >= 0; $i--) {
            if ($tokens[$i] === 'Tf') {
                $tfIndex = $i;
                break;
            }
        }

        if ($tfIndex === null || $tfIndex < 2) {
            // No valid Tf sequence found (need at least /<alias> <size> Tf).
            return new self('Helv', 0.0, '0 g');
        }

        $size = (float) $tokens[$tfIndex - 1];

        $aliasToken = $tokens[$tfIndex - 2];
        $fontAlias = (str_starts_with($aliasToken, '/') && strlen($aliasToken) > 1)
            ? substr($aliasToken, 1)
            : 'Helv';

        // Color ops = all tokens before the /<alias> token.
        $colorTokens = array_slice($tokens, 0, $tfIndex - 2);
        $colorOps = $colorTokens !== [] ? implode(' ', $colorTokens) : '0 g';

        return new self($fontAlias, $size, $colorOps);
    }

    public function isAutoSize(): bool
    {
        return $this->size === 0.0;
    }
}
