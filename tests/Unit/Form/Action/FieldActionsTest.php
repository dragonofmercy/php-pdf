<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\Calculate;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;
use DragonOfMercy\PhpPdf\Form\Action\Format;
use DragonOfMercy\PhpPdf\Form\Action\Validate;
use PHPUnit\Framework\TestCase;

final class FieldActionsTest extends TestCase
{
    public function testFormatSetsBothKAndF(): void
    {
        $a = FieldActions::new()->format(Format::number());
        $scripts = $a->scripts();
        self::assertSame(['K', 'F'], array_keys($scripts));
        self::assertSame('AFNumber_Keystroke(2, 0, 0, 0, "", false);', $scripts['K']);
        self::assertSame('AFNumber_Format(2, 0, 0, 0, "", false);', $scripts['F']);
    }

    public function testCalculateValidateKeystrokeMouse(): void
    {
        $a = FieldActions::new()
            ->calculate(Calculate::sum(['a']))
            ->validate(Validate::range(0, 9))
            ->onMouseEnter('e();');
        $scripts = $a->scripts();
        self::assertSame('AFSimple_Calculate("SUM", new Array("a"));', $scripts['C']);
        self::assertSame('AFRange_Validate(true, 0, true, 9);', $scripts['V']);
        self::assertSame('e();', $scripts['E']);
    }

    public function testDeterministicKeyOrder(): void
    {
        $a = FieldActions::new()
            ->onBlur('bl();')
            ->calculate(Calculate::custom('c();'))
            ->format(Format::number())
            ->onMouseEnter('e();');
        self::assertSame(['K', 'F', 'C', 'E', 'Bl'], array_keys($a->scripts()));
    }

    public function testHasCalculate(): void
    {
        self::assertFalse(FieldActions::new()->format(Format::number())->hasCalculate());
        self::assertTrue(FieldActions::new()->calculate(Calculate::custom('c();'))->hasCalculate());
    }

    public function testImmutability(): void
    {
        $base = FieldActions::new();
        $withCalc = $base->calculate(Calculate::custom('c();'));
        self::assertSame([], $base->scripts());
        self::assertSame(['C'], array_keys($withCalc->scripts()));
    }

    public function testFormatThenKeystrokeConflictsOnK(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("FieldActions: trigger 'K' is already set");
        FieldActions::new()->format(Format::number())->keystroke('x();');
    }

    public function testDuplicateMouseTriggerThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("FieldActions: trigger 'E' is already set");
        FieldActions::new()->onMouseEnter('a();')->onMouseEnter('b();');
    }

    public function testEmptyRawJsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('FieldActions: keystroke JavaScript cannot be empty');
        FieldActions::new()->keystroke('');
    }

    public function testAllMouseAndFocusSettersMapToTheirTriggers(): void
    {
        $a = FieldActions::new()
            ->onMouseExit('x();')
            ->onMouseDown('d();')
            ->onMouseUp('u();')
            ->onFocus('fo();');
        $scripts = $a->scripts();
        self::assertSame(['X', 'D', 'U', 'Fo'], array_keys($scripts));
        self::assertSame('x();', $scripts['X']);
        self::assertSame('d();', $scripts['D']);
        self::assertSame('u();', $scripts['U']);
        self::assertSame('fo();', $scripts['Fo']);
    }

    public function testEmptyMouseJsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('FieldActions: mouse-up JavaScript cannot be empty');
        FieldActions::new()->onMouseUp('');
    }
}
