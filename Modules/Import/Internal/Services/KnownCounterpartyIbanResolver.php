<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Account;

/**
 * Resolves real institution IBANs to the user's own synthetic-IBAN
 * accounts via the `known_counterparty_ibans` alias table.
 *
 * resolveAccount() mixes two read styles deliberately:
 *  - The alias lookup uses the raw query builder against
 *    `known_counterparty_ibans` because it sits on the per-row
 *    hot path of import ingestion and only needs a single
 *    `target_account_kind` string; skipping Eloquent hydration
 *    avoids unnecessary model construction on every imported row.
 *  - The user-account lookup uses the `Account` Eloquent model so
 *    consumers receive a typed Account object with `kind`, `iban`,
 *    `id` directly available — no second query in downstream code.
 *
 * Multi-account-per-kind disambiguation: the schema permits N
 * accounts per kind per user (e.g. a future ABN AMRO migration on
 * the same instance where the user owns two `bank`-kind accounts,
 * or a joint-account variant). The resolver picks the lowest-id
 * account via `orderBy('id')` for deterministic, schema-portable
 * behaviour across SQLite versions. Pinning the alias to a
 * specific Account.id at seed time would be a stronger contract
 * but requires a migration extension — deferred until the
 * multi-account-per-kind scenario actually lands in the product.
 *
 * Cross-user isolation is enforced by an explicit
 * `where('user_id', $userId)` filter on both queries. The
 * `BelongsToUser` global scope on the Eloquent model is a secondary
 * guard that only fires under an authenticated HTTP context; the
 * explicit filter is the load-bearing isolation in console / queued
 * import paths where no `auth()->id()` exists.
 */
final class KnownCounterpartyIbanResolver implements ResolvesKnownCounterpartyIban
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function resolveAccount(string $iban, int $userId): ?Account
    {
        if (trim($iban) === '') {
            return null;
        }

        $alias = $this->db->connection()
            ->table('known_counterparty_ibans')
            ->where('user_id', $userId)
            ->where('real_iban', $iban)
            ->value('target_account_kind');

        if (! is_string($alias) || $alias === '') {
            return null;
        }

        // Disambiguation: raw query builder returns the lowest-id
        // matching account id; Eloquent's find($id) then hydrates
        // the full model — sidesteps Larastan's staticMethod.dynamicCall
        // lint while still returning the typed Account the contract promises.
        $accountId = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->where('kind', $alias)
            ->orderBy('id')
            ->value('id');

        if (! is_numeric($accountId)) {
            return null;
        }

        return Account::query()->find((int) $accountId);
    }
}
