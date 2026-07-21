<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// The cursor pair (nextCursorPostedAt, nextCursorId) carries the last
// visible row's ordering key for `WHERE (posted_at, id) < (?, ?)`, and
// is null exactly when hasMore is false. The pair (not id alone)
// prevents rows sharing a posted_at from dropping out between pages.
final class TransactionListPage extends Data
{
    /**
     * @param  array<TransactionRowDto>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $hasMore,
        public readonly ?int $nextCursorId,
        public readonly ?string $nextCursorPostedAt = null,
    ) {}
}
