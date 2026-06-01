<?php
require 'D:/Seafile/Developpements/PHP/PhpPdf/build/vendor/autoload.php';
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;

$gen = TestCertificate::generate();
$cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
$doc = new Document();
$page = $doc->addPage();
$page->field(SignatureField::visible(20,20,80,20,name:'signature'));
$doc->sign($cred, field:'signature', reason:'Approved',
  signedAt: new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')),
  timestamp: Tsa::withClient(new TestTsa()));
$bytes = $doc->output();

preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $m);
$a=(int)$m[1]; $s2=(int)$m[2]; $l2=(int)$m[3];
$signedData = substr($bytes,0,$a).substr($bytes,$s2,$l2);
$hex = substr($bytes, $a+1, ($s2-1)-($a+1));
$der = hex2bin($hex);

// 1) confirm token OID present exactly once
$oid = Der::oid('1.2.840.113549.1.9.16.2.14');
echo "token OID count in /Contents DER: ".substr_count($der,$oid)."\n";
echo "DER length bytes: ".strlen($der)."\n";
echo "signedData length: ".strlen($signedData)."\n";

// 2) negative control: verify against WRONG content must fail
file_put_contents('C:/tmp/d.der',$der);
file_put_contents('C:/tmp/good.bin',$signedData);
file_put_contents('C:/tmp/bad.bin',$signedData."X");
file_put_contents('C:/tmp/c.pem',$gen['certPem']);
$ossl='C:\Program Files\Git\usr\bin\openssl.exe';
foreach(['good'=>'C:/tmp/good.bin','bad'=>'C:/tmp/bad.bin'] as $k=>$f){
  $cmd = escapeshellarg($ossl)." cms -verify -binary -inform DER -in C:/tmp/d.der -content ".escapeshellarg($f)." -certfile C:/tmp/c.pem -noverify 2>&1";
  exec($cmd,$o,$rc);
  echo "verify[$k] rc=$rc\n";
  $o=[];
}
