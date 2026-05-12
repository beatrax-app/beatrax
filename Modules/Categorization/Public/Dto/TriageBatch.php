<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One cursor-paginated batch of uncategorized transactions ready for the
 * triage inbox. `nextCursorId` is the smallest `id` of the rendered page
 * when more rows exist beyond it; `null` when the user has reached the end.
 */
final class TriageBatch extends Data
{
    public function __construct(
        /** @var array<int, TriageRow> */
        public readonly array $rows,
        public readonly bool $hasMore,
        public readonly ?int $nextCursorId,
    ) {}
}
