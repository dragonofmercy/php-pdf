<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\AcroFormEmitter;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class AcroFormSignatureWiringTest extends TestCase
{
    private function sig(string $field): Signature
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        return new Signature($cred, $field, null, null, null, new DateTimeImmutable(), 8192);
    }

    public function testSignedFieldGetsVReferenceAndSigDictEmitted(): void
    {
        $field = SignatureField::visible(10, 10, 60, 20, name: 'sig');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit(
            $widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test',
            $this->sig('sig'), new SignatureDictionaryEmitter(),
        );
        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $serialized);
        self::assertMatchesRegularExpression('~/V \d+ 0 R~', $serialized);
    }

    public function testSignedFieldNameNotFoundThrows(): void
    {
        $field = SignatureField::visible(10, 10, 60, 20, name: 'sig');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test', $this->sig('absent'), new SignatureDictionaryEmitter());
    }
}
