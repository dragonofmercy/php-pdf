<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\PushButton;
use PHPUnit\Framework\TestCase;

final class PushButtonTest extends TestCase
{
    public function testValidButtonExposesFormFieldContract(): void
    {
        $b = PushButton::of(x: 10, y: 20, width: 40, height: 10, name: 'go', caption: 'Go', action: ButtonAction::resetForm());
        self::assertSame('go', $b->name());
        self::assertSame(['x' => 10.0, 'y' => 20.0, 'width' => 40.0, 'height' => 10.0], $b->dimensions());
        self::assertNull($b->appearance());
    }

    public function testEmptyCaptionAllowed(): void
    {
        $b = PushButton::of(x: 10, y: 20, width: 40, height: 10, name: 'go', caption: '', action: ButtonAction::resetForm());
        self::assertSame('', $b->caption);
    }

    public function testRejectsNonPositiveSize(): void
    {
        $this->expectException(PdfException::class);
        PushButton::of(x: 10, y: 20, width: 0, height: 10, name: 'go', caption: 'Go', action: ButtonAction::resetForm());
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(PdfException::class);
        PushButton::of(x: 10, y: 20, width: 40, height: 10, name: '', caption: 'Go', action: ButtonAction::resetForm());
    }
}
