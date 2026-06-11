<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Form\Fill;

enum FormFieldType
{
    case Text;
    case Checkbox;
    case Radio;
    case Combobox;
    case Listbox;
    case PushButton;
    case Signature;
}
