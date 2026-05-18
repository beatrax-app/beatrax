<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Funder mapping for a single occurrence of a recurring series. The
 * Public projection lookup `ChainLinkQuery::confirmedAndDeterministicForSeries`
 * returns one of these per occurrence transaction that carries a
 * confirmed or auto-resolver chain link to a funder account.
 *
 * `funderAccountId` is the account whose balance dips on the funder's
 * actual debit date — the ASN (or ICS) account underwriting the
 * downstream charge. `kind` is the chain_links.kind (`paypal_funding`
 * or `ics_bulk_settle`); the consumer routes contributions based on
 * the chain semantics this represents.
 */
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
