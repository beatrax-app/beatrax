<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Ledger\Public\Enums\TransactionType;

// The fields that differ between the two legs, grouped so the insert helper
// stays inside the parameter budget.
final class DemoTransferLeg
{
    public function __construct(
        public readonly TransactionType $type,
        public readonly int $amountMinor,
        public readonly string $description,
        public readonly string $sourceRef,
    ) {}
}
