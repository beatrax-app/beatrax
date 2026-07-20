<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/import/architecture.md#consolidated-preview-multi-run-commit
 */
final class ConsolidatedPreviewBatch extends Data
{
    /**
     * @param  list<ConsolidatedPreviewSection>  $sections
     */
    public function __construct(
        public readonly array $sections,
        public readonly int $dedupedTotalCount,
        public readonly int $alreadyImportedCount,
    ) {}
}
