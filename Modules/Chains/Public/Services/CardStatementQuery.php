<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\CardStatement;
use Modules\Core\Models\User;

/**
 * Public read API over `card_statements`. Powers the chain drawer's
 * "open statement" card and the dashboard's ICS-funding tile.
 *
 * Cross-user safety: every query filters on `user_id = $user->id`
 * before any other condition. The DatabaseManager is injected so the
 * `auth()` / `Auth::user()` facade indirection stays out of the
 * Public surface — callers pass the resolved User explicitly.
 *
 * Returns the most recent `open` / `partially_settled` statement on
 * the given account, or null when no such row exists. `period_end`
 * descending is the recency sort because Wave 1 back-population
 * stamps `period_end` from the statement-summary header line; rows
 * inserted later for the same account always carry a later
 * `period_end`.
 */
final class CardStatementQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function openForAccount(int $accountId, User $user): ?CardStatement
    {
        // Read via raw query builder so the user-scope filter precedes
        // the account filter, then hydrate the Eloquent model via id.
        // Mirrors the strict-rules-clean pattern used by
        // ConfirmImport::needsIcsAccountName / PreviewWizard.
        $row = $this->db->connection()->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', ['open', 'partially_settled'])
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        // The user-scope filter on the read above is the cross-user
        // safety boundary; this `where('id', ...)->first()` trusts the
        // id it returns. `first()` typed as `?CardStatement` — `find()`
        // unions a Collection variant that the Larastan return-type
        // narrowing does not collapse here.
        return CardStatement::query()->where('id', $row->id)->first();
    }
}
