<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

// The cursor is a pair rather than an id alone, so rows sharing a posted_at
// cannot drop out of the inbox between pages.
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
