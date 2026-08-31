<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Spatie\LaravelData\Data;

final class EnvelopeMoveRow extends Data
{
    public function __construct(
        public readonly int $id,
        // Null for a kind this build has no case for. `envelope_moves.kind` is
        // an unconstrained string a peer on a newer version writes verbatim,
        // and the history line takes its direction from this field — so a
        // guess here would show the reader the wrong side of a real move.
        public readonly ?EnvelopeMoveKind $kind,
        public readonly int $amountMinor,
        // What $amountMinor is denominated in: the reader's own currency once
        // the rate table can reach the row's, and the row's own when it cannot.
        public readonly string $currency,
        public readonly int $counterpartCategoryId,
        public readonly string $counterpartCategoryName,
        public readonly ?string $memo,
        public readonly string $createdAt,
    ) {}
}
