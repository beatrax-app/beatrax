<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Ledger\Models\Account;

/**
 * Resolves a real institution IBAN (the one that appears on the ASN
 * side of a cross-account hop) to the user's own Account whose kind
 * matches the alias's target_account_kind.
 *
 * The contract returns the full Account model so consumers can read
 * `kind`, `iban`, `id` without a second query. Cross-module
 * consumers in the Transfers and Chains modules depend on this
 * Public surface; no consumer reaches into
 * `Modules\Import\Internal\*` for the resolution.
 *
 * When the user owns more than one account of the alias's target
 * kind (e.g. two `bank`-kind accounts), the lowest-id account wins
 * for deterministic, schema-portable behaviour across SQLite
 * versions. A future schema extension pinning each alias to a
 * specific Account.id would tighten the contract; until then,
 * lowest-id-wins is the canonical disambiguation rule.
 */
interface ResolvesKnownCounterpartyIban
{
    /**
     * Returns the user's own Account whose kind matches the alias
     * registered for `$iban`, or null when no alias exists for this
     * user or when the user does not own an account of the alias's
     * target kind. When the user owns multiple accounts of the
     * alias's target kind, the lowest-id account wins.
     */
    public function resolveAccount(string $iban, int $userId): ?Account;
}
