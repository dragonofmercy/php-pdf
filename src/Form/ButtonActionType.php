<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

/**
 * Activation-action kinds a {@see PushButton} can carry. v1 supports a URL
 * link and a form reset; submitForm and named JavaScript are later features.
 */
enum ButtonActionType
{
    case OpenUrl;
    case ResetForm;
}
