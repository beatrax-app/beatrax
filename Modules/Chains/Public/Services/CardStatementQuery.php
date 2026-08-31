<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Dto\CardStatementForecastTile;
use Modules\Chains\Public\Dto\NextSettlementDto;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Chains\Public\Support\StatementDueDate;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

final readonly class CardStatementQuery
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

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

        // The raw read above is the cross-user boundary; this lookup trusts the
        // id it returned.
        return CardStatement::query()->where('id', $row->id)->first();
    }

    public function nextSettlementForUser(User $user): ?NextSettlementDto
    {
        $row = $this->openIcsStatementRow($user);

        if ($row === null) {
            return null;
        }

        $funderId = $this->funderAccountId(self::toInt($row->card_account_id), $user);

        if ($funderId === null) {
            return null;
        }

        // Every field but the funder is read off the tile, so the two surfaces
        // cannot state one statement at two amounts or on two dates.
        $tile = $this->forecastTile($row, $user);

        return new NextSettlementDto(
            accountId: $funderId,
            amount: $tile->amount,
            dueDate: $tile->dueDate,
            statementId: $tile->statementId,
            state: $tile->state,
        );
    }

    // The one answer to what the open statement will cost and when. Selecting
    // the row outside this module reads open_balance_minor as the figure, which
    // payableMinor() below is the whole reason it is not.
    public function forecastTileForUser(User $user): ?CardStatementForecastTile
    {
        $row = $this->openIcsStatementRow($user);

        return $row === null ? null : $this->forecastTile($row, $user);
    }

    // The WHERE filters card_statements.user_id before any account join, so a
    // forged user_id leaks nothing.
    private function openIcsStatementRow(User $user): ?stdClass
    {
        return $this->db->connection()->table('card_statements')
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
                'card_statements.currency as currency',
                'card_statements.period_end as period_end',
                'card_statements.due_date as due_date',
                'card_statements.state as state',
            )
            ->first();
    }

    private function forecastTile(stdClass $row, User $user): CardStatementForecastTile
    {
        $statementId = self::toInt($row->statement_id);
        $currency = self::toString($row->currency);

        return new CardStatementForecastTile(
            amount: Money::ofMinor(
                $this->payableMinor($statementId, self::toInt($row->open_balance_minor), $currency, $user),
                $currency,
            ),
            dueDate: StatementDueDate::of(
                self::toStringOrNull($row->due_date ?? null),
                self::toString($row->period_end),
            ),
            statementId: $statementId,
            state: self::toString($row->state),
        );
    }

    private function funderAccountId(int $cardAccountId, User $user): ?int
    {
        // An ics_bulk_settle link runs payment → charge: `from` is the one
        // settlement and `to` is each charge it covered. Read the other way
        // round the payer filter names an account the payment can never sit on,
        // so no history ever answered and every reader got the fallback.
        $historicalFunder = $this->db->connection()->table('chain_links')
            ->join('transactions as funder_tx', 'funder_tx.id', '=', 'chain_links.from_transaction_id')
            ->join('transactions as funded_tx', 'funded_tx.id', '=', 'chain_links.to_transaction_id')
            ->where('chain_links.user_id', $user->id)
            ->where('chain_links.kind', ChainLinkKind::IcsBulkSettle->value)
            ->where('chain_links.state', ChainLinkState::Confirmed->value)
            ->where('funded_tx.account_id', $cardAccountId)
            // The refund-after-close pass writes card → card links of this same
            // kind, and a refund is not a payer: the card would name itself.
            ->where('funder_tx.account_id', '!=', $cardAccountId)
            ->whereNotNull('chain_links.to_transaction_id')
            ->orderByDesc('chain_links.created_at')
            ->orderByDesc('chain_links.id')
            ->value('funder_tx.account_id');

        // Only the card is excluded, the same line IcsSettlementResolver's
        // candidateTransferIds() already had to be corrected to: pinning the
        // payer to `bank` answered "no settlement due" to every reader who pays
        // their card from anything else, so the tile never drew at all.
        $historicalFunder ??= $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->where('kind', '!=', AccountKind::IcsCard->value)
            ->orderBy('id')
            ->value('id');

        return $historicalFunder === null ? null : self::toInt($historicalFunder);
    }

    // What actually leaves the bank account: IcsSettlementResolver hands the
    // state machine payment PLUS the credits carried in and lands on zero, so
    // projecting the raw open balance deducts a settlement no pass can match.
    // Same currency predicate priorCreditsMinor() draws, and the same floor.
    private function payableMinor(int $statementId, int $openBalanceMinor, string $currency, User $user): int
    {
        $credits = self::toInt(
            $this->db->connection()->table('card_statement_credits')
                ->where('user_id', $user->id)
                ->where('to_statement_id', $statementId)
                ->where('currency', $currency)
                ->sum('amount_minor'),
        );

        return max(0, $openBalanceMinor - $credits);
    }
}
