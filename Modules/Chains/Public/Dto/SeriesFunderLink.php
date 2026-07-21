<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Spatie\LaravelData\Data;

// funderAccountId is the account whose balance dips on the funder's
// actual debit date — the ASN (or ICS) account underwriting the
// downstream charge.
final class SeriesFunderLink extends Data
{
    public function __construct(
        public readonly int $chainLinkId,
        public readonly int $fromTransactionId,
        public readonly int $toTransactionId,
        public readonly int $funderAccountId,
        public readonly string $kind,
        public readonly string $state,
        public readonly string $resolver,
        public readonly float $confidence,
    ) {}
}
