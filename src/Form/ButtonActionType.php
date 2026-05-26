<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

/**
 * Activation-action kinds a {@see PushButton} can carry: a URL link, a form
 * reset, and a form submission. Named JavaScript actions remain a later feature.
 */
enum ButtonActionType
{
    case OpenUrl;
    case ResetForm;
    case SubmitForm;
}
