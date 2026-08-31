<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Modules\Pots\Public\Enums\PotMovementKind;
use Spatie\LaravelData\Data;

final class PotMovementRow extends Data
{
    public function __construct(
        public readonly int $id,
        // Null for a kind this build has no case for. `pot_movements.kind` is
        // an unconstrained string a peer on a newer version writes verbatim,
        // and the history line takes its sign and its wording from this field.
        public readonly ?PotMovementKind $kind,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $counterpartPotId,
        public readonly ?string $counterpartPotName,
        public readonly ?string $memo,
        public readonly string $createdAt,
    ) {}
}
