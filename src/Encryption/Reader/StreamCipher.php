<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Encryption\Reader;

/**
 * The symmetric cipher a Standard security handler applies to streams and
 * strings: legacy RC4, AES-128-CBC (V4 /AESV2) or AES-256-CBC (V5/R6 /AESV3).
 *
 * @internal
 */
enum StreamCipher
{
    case Rc4;
    case Aesv2;
    case Aesv3;
}
