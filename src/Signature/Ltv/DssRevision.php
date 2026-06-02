<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

/**
 * Marker queued in the appended-revision list to request a Document Security
 * Store revision carrying the given validation material. Unlike a signature
 * revision it has no /Contents to patch.
 *
 * @internal
 */
final readonly class DssRevision
{
    public function __construct(public ValidationMaterial $material) {}
}
