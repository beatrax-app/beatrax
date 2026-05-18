<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Dto\NextSettlementDto;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;

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

    /**
     * Returns the next ICS bulk-iDEAL settlement projected for the
     * user, with the FUNDER ASN account resolved.
     *
     * The funder is determined by the most-recent confirmed
     * `ics_bulk_settle` chain_link for any ICS expense on the same
     * card account (i.e. the ASN account the user historically uses
     * to settle this card). When no historical settlement exists,
     * falls back to the user's first `accounts.kind='asn'` row by id.
     *
     * Returns null when:
     *   - no open / partially_settled card_statement exists for the
     *     user, or
     *   - the user has zero ASN accounts (graceful degradation; the
     *     dashboard tile + forecasting router skip the synthesis).
     *
     * Cross-user safety: every read filters on `user_id` before any
     * join — card_statement read uses `card_statements.user_id`, the
     * chain_link funder lookup uses `chain_links.user_id`, and the
     * ASN fallback uses `accounts.user_id`.
     */
    public function nextSettlementForUser(User $user): ?NextSettlementDto
    {
        $row = $this->db->connection()->table('card_statements')
            ->join('accounts', 'accounts.id', '=', 'card_statements.account_id')
            ->where('card_statements.user_id', $user->id)
            ->where('accounts.kind', 'ics_card')
            ->whereIn('card_statements.state', ['open', 'partially_settled'])
            ->orderByDesc('card_statements.period_end')
            ->orderByDesc('card_statements.id')
            ->select(
                'card_statements.id as statement_id',
                'card_statements.account_id as card_account_id',
                'card_statements.open_balance_minor as open_balance_minor',
                'card_statements.period_end as period_end',
                'card_statements.state as state',
            )
            ->first();

        if ($row === null) {
            return null;
        }

        $cardAccountId = self::toInt($row->card_account_id);

        // Step A — funder lookup via the most-recent confirmed
        // ics_bulk_settle chain_link for any ICS expense on the same
        // card account.
        $historicalFunder = $this->db->connection()->table('chain_links')
            ->join('transactions as funder_tx', 'funder_tx.id', '=', 'chain_links.to_transaction_id')
            ->join('transactions as funded_tx', 'funded_tx.id', '=', 'chain_links.from_transaction_id')
            ->where('chain_links.user_id', $user->id)
            ->where('chain_links.kind', 'ics_bulk_settle')
            ->where('chain_links.state', 'confirmed')
            ->where('funded_tx.account_id', $cardAccountId)
            ->whereNotNull('chain_links.to_transaction_id')
            ->orderByDesc('chain_links.created_at')
            ->value('funder_tx.account_id');

        // Step B — fallback to the user's first ASN account when no
        // historical settlement exists yet (fresh import scenario).
        if ($historicalFunder === null) {
            $historicalFunder = $this->db->connection()->table('accounts')
                ->where('user_id', $user->id)
                ->where('kind', 'asn')
                ->orderBy('id')
                ->value('id');
        }

        if ($historicalFunder === null) {
            return null;
        }

        $periodEnd = CarbonImmutable::parse(self::toString($row->period_end));

        return new NextSettlementDto(
            accountId: self::toInt($historicalFunder),
            amount: Money::ofMinor(self::toInt($row->open_balance_minor), 'EUR'),
            dueDate: $periodEnd->addDays(5)->startOfDay(),
            statementId: self::toInt($row->statement_id),
            state: self::toString($row->state),
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
