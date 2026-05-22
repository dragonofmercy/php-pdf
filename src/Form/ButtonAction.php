<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * The action triggered when a push button is clicked. Immutable; build via the
 * named constructors. v1: open a URL, or reset the whole form.
 */
final readonly class ButtonAction
{
    private function __construct(
        private ButtonActionType $type,
        private ?string $url,
    ) {
    }

    public static function openUrl(string $url): self
    {
        if ($url === '') {
            throw new PdfException('ButtonAction::openUrl requires a non-empty URL');
        }
        return new self(ButtonActionType::OpenUrl, $url);
    }

    public static function resetForm(): self
    {
        return new self(ButtonActionType::ResetForm, null);
    }

    public function type(): ButtonActionType
    {
        return $this->type;
    }

    public function url(): ?string
    {
        return $this->url;
    }
}
