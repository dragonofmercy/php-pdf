<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\ButtonActionType;
use DragonOfMercy\PhpPdf\Form\SubmitFormat;
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

    public function testSubmitFdfDefaults(): void
    {
        $a = ButtonAction::submit('https://example.com/post');
        self::assertSame(ButtonActionType::SubmitForm, $a->type());
        self::assertSame('https://example.com/post', $a->url());
        self::assertSame(0, $a->flags());
    }

    public function testSubmitHtmlFlags(): void
    {
        self::assertSame(4, ButtonAction::submit('https://x.test', SubmitFormat::HTML)->flags());
    }

    public function testSubmitXfdfFlags(): void
    {
        self::assertSame(32, ButtonAction::submit('https://x.test', SubmitFormat::XFDF)->flags());
    }

    public function testSubmitPdfFlags(): void
    {
        self::assertSame(256, ButtonAction::submit('https://x.test', SubmitFormat::PDF)->flags());
    }

    public function testSubmitGetAddsEight(): void
    {
        self::assertSame(12, ButtonAction::submit('https://x.test', SubmitFormat::HTML, get: true)->flags());
    }

    public function testSubmitRejectsEmptyUrl(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ButtonAction::submit requires a non-empty URL');
        ButtonAction::submit('');
    }

    public function testNonSubmitActionsHaveNullFlags(): void
    {
        self::assertNull(ButtonAction::openUrl('https://x.test')->flags());
        self::assertNull(ButtonAction::resetForm()->flags());
    }
}
