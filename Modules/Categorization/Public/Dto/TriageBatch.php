<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One cursor-paginated batch of uncategorized transactions ready for the
 * triage inbox. The cursor pair `(nextCursorPostedAt, nextCursorId)` carries
 * the last visible row's ordering key when more rows exist; both fields are
 * null exactly when the user has reached the end. Using the pair rather than
 * the id alone is what prevents rows with a matching `posted_at` and a
 * higher `id` from silently dropping out of the inbox between pages.
 */
final class TriageBatch extends Data
{
    public function __construct(
        /** @var array<int, TriageRow> */
        public readonly array $rows,
        public readonly bool $hasMore,
        public readonly ?int $nextCursorId,
        public readonly ?string $nextCursorPostedAt = null,
    ) {}
}
