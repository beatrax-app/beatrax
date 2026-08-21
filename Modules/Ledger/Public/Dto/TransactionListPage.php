<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// The cursor is the pair (posted_at, id), never id alone, so rows sharing a
// posted_at cannot drop out between pages. Null exactly when hasMore is false.
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
