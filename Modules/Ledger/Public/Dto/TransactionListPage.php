<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One cursor-paginated page of transaction rows. `hasMore` is true when the
 * underlying query found at least one row past the requested limit; the
 * cursor pair `(nextCursorPostedAt, nextCursorId)` carries the last visible
 * row's ordering key so the next page can apply
 * `WHERE (posted_at, id) < (?, ?)`.
 *
 * Both cursor fields are null exactly when `hasMore` is false. Using the
 * pair (date + id) rather than the id alone is what prevents rows with a
 * matching `posted_at` and a higher `id` from silently dropping out of the
 * listing between pages.
 */
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
