<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

// The cursor pair (nextCursorPostedAt, nextCursorId) carries the last
// visible row's ordering key when more rows exist; using the pair rather
// than the id alone prevents rows with a matching posted_at and a higher
// id from silently dropping out of the inbox between pages.
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
