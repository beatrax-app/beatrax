<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Account;

// Multi-account-per-kind: the resolver picks the lowest-id account for
// deterministic behaviour. Cross-user isolation is the explicit
// where('user_id', $userId) filter — BelongsToUser's global scope is
// only a secondary guard (console/queued paths have no auth()->id()).
final class KnownCounterpartyIbanResolver implements ResolvesKnownCounterpartyIban
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function resolveAccount(string $iban, int $userId): ?Account
    {
        if (trim($iban) === '') {
            return null;
        }

        $kind = $this->aliasedAccountKind($iban, $userId);

        return $kind === null ? null : $this->lowestIdAccountOfKind($kind, $userId);
    }

    private function aliasedAccountKind(string $iban, int $userId): ?string
    {
        $alias = $this->db->connection()
            ->table('known_counterparty_ibans')
            ->where('user_id', $userId)
            ->where('real_iban', $iban)
            ->value('target_account_kind');

        return is_string($alias) && $alias !== '' ? $alias : null;
    }

    // Disambiguation: raw query builder returns the lowest-id matching
    // account id; Eloquent's find($id) then hydrates the full model —
    // sidesteps Larastan's staticMethod.dynamicCall lint while still
    // returning the typed Account the contract promises.
    private function lowestIdAccountOfKind(string $kind, int $userId): ?Account
    {
        $accountId = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->orderBy('id')
            ->value('id');

        return is_numeric($accountId) ? Account::query()->find((int) $accountId) : null;
    }
}
