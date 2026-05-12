<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One cursor-paginated page of transaction rows. `hasMore` is true when the
 * underlying query found at least one row past the requested limit;
 * `nextCursorId` carries the last visible row's id so the next page can
 * apply a `WHERE id < $nextCursorId` filter.
 *
 * `nextCursorId` is null exactly when `hasMore` is false.
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
    ) {}
}
