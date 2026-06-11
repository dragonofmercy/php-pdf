<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Form\Fill\FormFieldInfo;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use PHPUnit\Framework\TestCase;

final class FormFieldInfoTest extends TestCase
{
    public function testHoldsAllAttributes(): void
    {
        $info = new FormFieldInfo(
            name: 'address.city',
            type: FormFieldType::Text,
            value: 'Paris',
            options: [],
            readOnly: false,
            required: true,
            multiline: false,
        );
        self::assertSame('address.city', $info->name);
        self::assertSame(FormFieldType::Text, $info->type);
        self::assertSame('Paris', $info->value);
        self::assertSame([], $info->options);
        self::assertTrue($info->required);
    }

    public function testChoiceOptionsAndListValue(): void
    {
        $info = new FormFieldInfo(
            name: 'langs',
            type: FormFieldType::Listbox,
            value: ['fr', 'en'],
            options: ['fr', 'en', 'de'],
            readOnly: false,
            required: false,
            multiline: false,
        );
        self::assertSame(['fr', 'en'], $info->value);
        self::assertSame(['fr', 'en', 'de'], $info->options);
    }
}
