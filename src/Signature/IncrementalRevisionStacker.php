<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevision;
use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevisionBuilder;
use DragonOfMercy\PhpPdf\Writer\IncrementalWriter;

/**
 * Stacks a list of appended incremental revisions (signatures, document
 * timestamps, a DSS) onto already-finalized PDF bytes, evolving the
 * RevisionContext between each so later revisions cover all earlier ones.
 * Shared by Document and PdfEditor.
 *
 * @internal
 */
final readonly class IncrementalRevisionStacker
{
    public function __construct(
        private AppendedFieldRevisionBuilder $builder = new AppendedFieldRevisionBuilder(),
    ) {}

    /**
     * @param list<AppendedRevision|DssRevision> $revisions
     * @param array<string, SignatureFieldPlan> $plans
     */
    public function stack(string $bytes, RevisionContext $ctx, array $revisions, array $plans = []): string
    {
        foreach ($revisions as $revision) {
            if ($revision instanceof DssRevision) {
                $built = (new DssRevisionBuilder())->build($ctx, $revision->material);
                $prevStartxref = $this->lastStartxrefOffset($bytes);
                $bytes = (new IncrementalWriter())->append(
                    priorBytes: $bytes,
                    newObjects: $built['objects'],
                    root: $ctx->catalog->reference(),
                    documentId: $ctx->documentId,
                    prevStartxref: $prevStartxref,
                    size: $built['size'],
                );
                $ctx = $built['context'];
                continue;
            }

            $name = $revision->fieldName();
            $plan = $plans[$name] ?? null;
            $valueDict = $revision->valueDict(...);
            if ($plan === null) {
                $built = $this->builder->build($ctx, $valueDict, $name);
            } elseif ($plan->existingField !== null) {
                $built = $this->builder->buildReuse($ctx, $valueDict, $plan->existingField);
            } elseif ($plan->visible !== null) {
                $built = $this->builder->buildVisible($ctx, $valueDict, $name, $plan->visible['page'], $plan->visible['rect'], $plan->visible['appearance']);
            } else {
                throw new PdfException("Signature field plan for '{$name}' selects neither an existing field nor a visible one");
            }

            $searchFrom = strlen($bytes);
            $prevStartxref = $this->lastStartxrefOffset($bytes);
            $bytes = (new IncrementalWriter())->append(
                priorBytes: $bytes,
                newObjects: $built['objects'],
                root: $ctx->catalog->reference(),
                documentId: $ctx->documentId,
                prevStartxref: $prevStartxref,
                size: $built['size'],
            );
            $bytes = (new ContentRangePatcher())->patch(
                $bytes,
                $searchFrom,
                $revision->maxSignatureBytes() * 2,
                $revision->fill(...),
            );

            $ctx = $built['context'];
        }

        return $bytes;
    }

    private function lastStartxrefOffset(string $bytes): int
    {
        $pos = strrpos($bytes, 'startxref');
        if ($pos !== false && preg_match('~startxref\s+(\d+)~', substr($bytes, $pos), $m) === 1) {
            return (int) $m[1];
        }
        throw new PdfException('Could not locate the prior revision startxref offset');
    }
}
