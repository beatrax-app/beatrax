<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;
use stdClass;

/**
 * Read-only result envelope for AliasMatchPreviewQuery — the "test
 * against my transactions" probe shown next to the editable
 * generalized pattern in Settings → Aliases (and inside the rename
 * popover's preview line).
 *
 * Two factories cover the only two states the consumer cares about:
 * `withMatches` for the populated result + the bounded first-five
 * sample, and `withoutMatches` for the early-rejection cases (pattern
 * too short, no transactions in the user's window). Carrying
 * `emptyMessage` only on the empty factory means the consumer can
 * branch on `$result->total === 0` or `$result->emptyMessage !== null`
 * to choose between the populated list view and a calm explanatory
 * line.
 *
 * `first5` carries up to five `stdClass` rows pulled by the query
 * (columns: `id`, `description`, `counterparty_name`, `booked_at`,
 * `amount_minor`). The query is read-only; the rows are never
 * persisted back through this DTO.
 */
final class AliasMatchPreviewResultDto extends Data
{
    /**
     * @param  list<stdClass>  $first5
     */
    public function __construct(
        public readonly int $total,
        public readonly array $first5,
        public readonly ?string $emptyMessage = null,
    ) {}

    /**
     * @param  list<stdClass>  $first5
     */
    public static function withMatches(int $total, array $first5): self
    {
        return new self(
            total: $total,
            first5: $first5,
            emptyMessage: null,
        );
    }

    public static function withoutMatches(string $message): self
    {
        return new self(
            total: 0,
            first5: [],
            emptyMessage: $message,
        );
    }
}
