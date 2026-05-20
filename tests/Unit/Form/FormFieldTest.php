<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Form\FormField;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FormFieldTest extends TestCase
{
    public function testInterfaceExistsAndDeclaresName(): void
    {
        $reflection = new ReflectionClass(FormField::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('name'));

        $method = $reflection->getMethod('name');
        $returnType = $method->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('string', (string) $returnType);
    }
}
