<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Dto\NextSettlementDto;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../../../.docs/features/chains/architecture.md
 */
final class CardStatementQuery
{
    use CoercesScalars;

    public const int STATEMENT_DUE_GRACE_DAYS = 5;

    public function __construct(private readonly DatabaseManager $db) {}

    public function openForAccount(int $accountId, User $user): ?CardStatement
    {
        // Reads via raw query builder so the user-scope filter precedes
        // the account filter, then hydrates the Eloquent model via id.
        $row = $this->db->connection()->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', [CardStatementState::Open->value, CardStatementState::PartiallySettled->value])
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        // The user-scope filter above is the cross-user safety boundary;
        // this where('id', ...)->first() trusts the id it returns.
        return CardStatement::query()->where('id', $row->id)->first();
    }

    // Resolves the funder as the ASN account behind the most-recent
    // confirmed ics_bulk_settle chain_link, falling back to the user's
    // first ASN account when no history exists; null with zero ASN accounts.
    public function nextSettlementForUser(User $user): ?NextSettlementDto
    {
        $row = $this->db->connection()->table('card_statements')
            ->join('accounts', 'accounts.id', '=', 'card_statements.account_id')
            ->where('card_statements.user_id', $user->id)
            ->where('accounts.kind', AccountKind::IcsCard->value)
            ->whereIn('card_statements.state', [CardStatementState::Open->value, CardStatementState::PartiallySettled->value])
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

        $historicalFunder = $this->db->connection()->table('chain_links')
            ->join('transactions as funder_tx', 'funder_tx.id', '=', 'chain_links.to_transaction_id')
            ->join('transactions as funded_tx', 'funded_tx.id', '=', 'chain_links.from_transaction_id')
            ->where('chain_links.user_id', $user->id)
            ->where('chain_links.kind', ChainLinkKind::IcsBulkSettle->value)
            ->where('chain_links.state', ChainLinkState::Confirmed->value)
            ->where('funded_tx.account_id', $cardAccountId)
            ->whereNotNull('chain_links.to_transaction_id')
            ->orderByDesc('chain_links.created_at')
            ->value('funder_tx.account_id');

        if ($historicalFunder === null) {
            $historicalFunder = $this->db->connection()->table('accounts')
                ->where('user_id', $user->id)
                ->where('kind', AccountKind::Asn->value)
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
            dueDate: $periodEnd->addDays(self::STATEMENT_DUE_GRACE_DAYS)->startOfDay(),
            statementId: self::toInt($row->statement_id),
            state: self::toString($row->state),
        );
    }
}
