<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

/**
 * Hero-section shape for a single counterparty's profile page.
 *
 * Carries the counterparty's identity (id, slug, display name, type)
 * plus the hero stats the profile header surfaces: the 12-month total
 * for spend-type counterparties (merchant/bank/government), the net
 * received for personal contacts, and the count of underlying
 * transactions.
 *
 * The privacy default for personal counterparties lives here: the
 * `iban` field IS populated, but every page-rendering path branches
 * on whether the user explicitly clicked Show IBAN before echoing the
 * value. The slug never carries the IBAN regardless of opt-in state.
 */
final readonly class CounterpartyProfileDto
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $type,
        public ?string $iban,
        public ?string $merchantName,
        public int $total12mMinor,
        public int $transactionCount,
        public ?string $firstSeenDate,
        public ?string $lastSeenDate,
    ) {}
}
