<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\ButtonActionType;
use PHPUnit\Framework\TestCase;

final class ButtonActionTest extends TestCase
{
    public function testOpenUrlCarriesTypeAndUrl(): void
    {
        $a = ButtonAction::openUrl('https://example.com');
        self::assertSame(ButtonActionType::OpenUrl, $a->type());
        self::assertSame('https://example.com', $a->url());
    }

    public function testResetFormHasNoUrl(): void
    {
        $a = ButtonAction::resetForm();
        self::assertSame(ButtonActionType::ResetForm, $a->type());
        self::assertNull($a->url());
    }

    public function testOpenUrlRejectsEmpty(): void
    {
        $this->expectException(PdfException::class);
        ButtonAction::openUrl('');
    }
}
