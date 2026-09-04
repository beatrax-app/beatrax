<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

/**
 * @link ../../../../../.docs/features/ledger/architecture.md#reconcile--account-reconciliation
 */
final class ReconcilePage extends Component
{
    use CoercesScalars;
    use DispatchesToast;

    #[Url(except: null)]
    public ?int $accountId = null;

    public string $statementBalance = '';

    public string $statementDate = '';

    #[Locked]
    public string $error = '';

    public function mount(Clock $clock, CurrentUser $currentUser, DatabaseManager $db, BaseCurrency $baseCurrency, ?int $accountId = null): void
    {
        if ($accountId !== null) {
            $this->accountId = $accountId;
        }

        $this->statementDate = $clock->now()->toDateString();
        $this->loadAccount($currentUser, $db, $baseCurrency);
    }

    // Clears the stale balance and date before re-running the pre-fill, so a
    // value from the previous account never bleeds through.
    public function updatedAccountId(mixed $value, Clock $clock, CurrentUser $currentUser, DatabaseManager $db, BaseCurrency $baseCurrency): void
    {
        $this->statementBalance = '';
        $this->statementDate = $clock->now()->toDateString();
        $this->loadAccount($currentUser, $db, $baseCurrency);
    }

    // Every field here feeds the difference, and render() recomputes that on
    // the same round trip. A refusal left standing would sit above a pill
    // already reading matched, naming a gap the reader has since closed.
    public function updated(): void
    {
        $this->error = '';
    }

    // Intentionally a no-op: render() already recomputes the difference on
    // every round trip. This is the UI's explicit "review the difference"
    // action, distinct from live-typing, and a stable name for tests to call.
    public function checkDiscrepancy(): void
    {
        $this->error = '';
    }

    // A discrepancy performs no write and creates no balancing transaction —
    // it surfaces as an error for the user to resolve.
    public function confirmReconcile(
        ReconciliationWriter $writer,
        CurrentUser $currentUser,
        AccountBalanceQuery $balances,
        DatabaseManager $db,
        BaseCurrency $baseCurrency,
    ): void {
        $this->error = '';

        $user = $currentUser->user();
        // The statement is denominated in the account's own currency, so the
        // figure typed off it is read at that currency's scale: a yen has no
        // minor unit, and the repo-wide hundredth read "-5000" as -JPY50.00
        // against a ledger holding -JPY5,000.
        $statementCurrency = $this->statementCurrency($db->connection(), $user->id, $baseCurrency);

        $target = MoneyInput::tryToMinor($this->statementBalance, $statementCurrency);
        $date = SafeDate::dayOrNull($this->statementDate);

        // Both are the same answer to the operator: the form is not yet in a
        // state this can act on, and the message says which part is missing.
        if ($this->accountId === null || $target === null || $date === null) {
            $this->error = $this->accountId === null
                ? Lang::get('ledger::reconcile.errors.choose_account')
                : Lang::get('ledger::reconcile.errors.invalid_balance_date');

            return;
        }

        // Bound the cleared balance by the statement date so this check
        // uses the same posted_at <= $date window completeReconcile()
        // locks — the unbounded balance would flag a spurious
        // discrepancy whenever a cleared row posts after the date.
        $cleared = $balances->clearedBalanceAsOf($this->accountId, $user, $date)
            ->in($statementCurrency);

        if ($target - $cleared !== 0) {
            $this->error = Lang::get('ledger::reconcile.errors.mismatch');

            return;
        }

        try {
            $lockedCount = $writer->completeReconcile($user, $this->accountId, $date);
        } catch (InvalidArgumentException) {
            // Foreign/missing accountId (IDOR) — silent no-op, mirrors the
            // cross-user convention used by PotWriter::archive et al.
            return;
        }

        // Report the truthful outcome. A matched target with no cleared
        // rows in the statement-date window locks nothing — don't claim it did.
        $message = $lockedCount === 0
            ? Lang::get('ledger::reconcile.toast.nothing_to_lock')
            : Lang::choice('ledger::reconcile.toast.complete', $lockedCount);

        $this->toast($message);
    }

    public function render(
        CurrentUser $currentUser,
        DatabaseManager $db,
        ViewFactory $views,
        AccountBalanceQuery $balances,
        BaseCurrency $baseCurrency,
        AccountStartingBalanceQuery $startingBalances,
    ): View {
        $user = $currentUser->user();
        $connection = $db->connection();

        $accounts = $connection->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'kind']);

        $ownedAccountId = $this->ownedAccountId($connection, $user->id);

        // The on-screen difference, the disabled-button gate and
        // confirmReconcile() must all agree on posted_at <= statementDate.
        $statementDate = SafeDate::dayOrNull($this->statementDate);

        $statementCurrency = $this->statementCurrency($connection, $user->id, $baseCurrency);

        // Null, never zero: the date picker offers Clear, and without a date
        // there is no window to sum over. Zero was a figure the screen printed
        // under "cleared balance" for an account that holds money.
        $clearedBalanceMinor = ($ownedAccountId !== null && $statementDate !== null)
            ? $balances->clearedBalanceAsOf($ownedAccountId, $user, $statementDate)->in($statementCurrency)
            : null;

        $statementTargetMinor = MoneyInput::tryToMinor($this->statementBalance, $statementCurrency);
        $differenceMinor = ($clearedBalanceMinor !== null && $statementTargetMinor !== null)
            ? $statementTargetMinor - $clearedBalanceMinor
            : null;
        $isMatched = $differenceMinor === 0;

        $baseline = $ownedAccountId === null ? null : $startingBalances->forAccount($ownedAccountId, $user);
        $baselineInStatementCurrency = ($baseline !== null && $baseline['currency'] === $statementCurrency)
            ? $baseline['minorUnits']
            : 0;

        $reachable = $differenceMinor === null || $isMatched || $this->zeroIsReachableByToggling(
            $connection,
            $user->id,
            $ownedAccountId,
            $statementDate,
            $statementCurrency,
            $statementTargetMinor - $baselineInStatementCurrency,
        );

        $view = $views->make('ledger::livewire.reconcile-page', [
            'accounts' => $accounts,
            'hasAccount' => $ownedAccountId !== null,
            'clearedBalanceMinor' => $clearedBalanceMinor,
            'statementTargetMinor' => $statementTargetMinor,
            'differenceMinor' => $differenceMinor,
            'isMatched' => $isMatched,
            'statementCurrency' => $statementCurrency,
            'isReachable' => $reachable,
            'hasBaseline' => $this->hasRecordedBaseline($connection, $user->id, $ownedAccountId),
            'lockableCount' => $this->lockableRowCount($connection, $user->id, $ownedAccountId, $statementDate),
            'reconciledThrough' => $this->reconciledThrough($connection, $user->id, $ownedAccountId),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('ledger::reconcile.page_title').' · Beatrax']);

        return $view;
    }

    // completeReconcile()'s own candidate predicate, asked before the press
    // instead of reported after it: a matched target over an empty candidate
    // set left Complete standing as the enabled primary action for a write
    // whose only possible answer was "nothing to lock".
    private function lockableRowCount(ConnectionInterface $connection, int $userId, ?int $accountId, ?CarbonImmutable $statementDate): int
    {
        if ($accountId === null || $statementDate === null) {
            return 0;
        }

        return $connection->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('status', ClearedStatus::Cleared->value)
            ->where('posted_at', '<=', $statementDate->toDateString())
            ->count();
    }

    // A completed reconcile records nothing but the rows, so the day is read
    // back off the locked ones — never the field on screen, which the reader
    // may have wound back. It can only under-state, which is the safe
    // direction: it may not claim a day no locked row stands behind.
    private function reconciledThrough(ConnectionInterface $connection, int $userId, ?int $accountId): ?CarbonImmutable
    {
        if ($accountId === null) {
            return null;
        }

        $latest = $connection->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('status', ClearedStatus::Reconciled->value)
            ->max('posted_at');

        return is_string($latest) ? SafeDate::normalisedDayOrNull($latest) : null;
    }

    // Keyed on an amount being recorded, not on the date beside it: the demo
    // shape carries a baseline with no date, and blaming a missing opening
    // balance there would name a cause the account does not have.
    private function hasRecordedBaseline(ConnectionInterface $connection, int $userId, ?int $accountId): bool
    {
        if ($accountId === null) {
            return false;
        }

        return $connection->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $userId)
            ->where(static function (Builder $either): void {
                $either->whereNotNull('starting_balance_minor')
                    ->orWhereNotNull('opening_balance_minor');
            })
            ->exists();
    }

    // Toggling a row in or out moves the cleared balance by that row's own
    // amount, so every balance the reader can reach lies between "every
    // negative row counted" and "every positive row counted". A target outside
    // that span is one no amount of toggling will ever close.
    private function zeroIsReachableByToggling(
        ConnectionInterface $connection,
        int $userId,
        ?int $accountId,
        ?CarbonImmutable $statementDate,
        string $statementCurrency,
        int $targetAboveBaseline,
    ): bool {
        if ($accountId === null || $statementDate === null) {
            return true;
        }

        // Locked rows are counted in too. Over-stating what the reader can
        // reach keeps the panel from ever calling a closable gap unclosable,
        // which is the only direction of this answer that could mislead.
        $bounds = $connection->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('settled_currency', $statementCurrency)
            ->where('posted_at', '<=', $statementDate->toDateString())
            ->selectRaw('coalesce(sum(case when settled_amount_minor < 0 then settled_amount_minor else 0 end), 0) as lower_minor')
            ->selectRaw('coalesce(sum(case when settled_amount_minor > 0 then settled_amount_minor else 0 end), 0) as upper_minor')
            ->first();

        if ($bounds === null) {
            return $targetAboveBaseline === 0;
        }

        return $targetAboveBaseline >= self::toInt($bounds->lower_minor)
            && $targetAboveBaseline <= self::toInt($bounds->upper_minor);
    }

    // Re-validates account ownership before reading anything; a foreign
    // accountId is cleared back to null so no other user's data leaks. The
    // per-kind source mapping is on the linked architecture page.
    private function loadAccount(CurrentUser $currentUser, DatabaseManager $db, BaseCurrency $baseCurrency): void
    {
        if ($this->accountId === null) {
            return;
        }

        $user = $currentUser->user();
        $connection = $db->connection();

        $account = $connection->table('accounts')
            ->where('id', $this->accountId)
            ->where('user_id', $user->id)
            ->first(['id', 'kind']);

        if ($account === null) {
            $this->accountId = null;

            return;
        }

        $kind = is_string($account->kind ?? null) ? $account->kind : '';

        // A card reads its issuer statement; everything else reads whatever
        // statement summary its import wrote. PayPal writes one, and writes
        // none at all when its rows settle in more than one currency — so a
        // blank here is a real absence, not a kind that never has a source.
        if ($kind === AccountKind::IcsCard->value) {
            $this->prefillFromCardStatement($connection, $user->id, $baseCurrency);
        } else {
            $this->prefillFromStatementSummary($connection, $user->id, $baseCurrency);
        }
    }

    private function prefillFromStatementSummary(ConnectionInterface $connection, int $userId, BaseCurrency $baseCurrency): void
    {
        // Confirmed runs only: the summary is written while the preview is
        // being built, so a file the reader discarded still has one. This
        // screen is where they agree the account really holds this much, and
        // Complete locks the rows against it.
        $row = $connection->table('statement_summaries')
            ->join('import_runs', 'import_runs.id', '=', 'statement_summaries.import_run_id')
            ->where('import_runs.status', ImportRunStatus::Confirmed->value)
            ->where('statement_summaries.user_id', $userId)
            ->where('statement_summaries.account_id', $this->accountId)
            ->whereNotNull('statement_summaries.closing_balance_minor')
            ->orderByDesc('statement_summaries.period_end')
            ->orderByDesc('statement_summaries.id')
            ->first([
                'statement_summaries.closing_balance_minor',
                'statement_summaries.closing_balance_date',
                'statement_summaries.period_end',
            ]);

        if ($row === null) {
            return;
        }

        $this->statementBalance = MoneyInput::formatMinor(
            self::toInt($row->closing_balance_minor),
            $this->statementCurrency($connection, $userId, $baseCurrency),
        );
        $rawDate = $row->closing_balance_date ?? $row->period_end ?? null;
        $prefill = is_string($rawDate) ? SafeDate::normalisedDayOrNull($rawDate) : null;
        if ($prefill !== null) {
            $this->statementDate = $prefill->toDateString();
        }
    }

    private function prefillFromCardStatement(ConnectionInterface $connection, int $userId, BaseCurrency $baseCurrency): void
    {
        // Confirmed runs only, as on the statement-summary branch: a summary
        // exists from the moment a file is PREVIEWED, and CardStatementUpserter
        // promotes every ICS summary it finds. Left joined because
        // import_run_id is nullOnDelete, and such a row IS in the ledger.
        $row = $connection->table('card_statements')
            ->leftJoin('import_runs', 'import_runs.id', '=', 'card_statements.import_run_id')
            ->where('card_statements.user_id', $userId)
            ->where('card_statements.account_id', $this->accountId)
            ->whereNotNull('card_statements.total_amount_minor')
            ->where(static function (Builder $ownItsRun): void {
                $ownItsRun->whereNull('card_statements.import_run_id')
                    ->orWhere('import_runs.status', ImportRunStatus::Confirmed->value);
            })
            ->orderByDesc('card_statements.period_end')
            ->orderByDesc('card_statements.id')
            ->first(['card_statements.total_amount_minor', 'card_statements.period_end']);

        if ($row === null) {
            return;
        }

        $this->statementBalance = MoneyInput::formatMinor(
            self::toInt($row->total_amount_minor),
            $this->statementCurrency($connection, $userId, $baseCurrency),
        );
        $prefill = is_string($row->period_end) ? SafeDate::normalisedDayOrNull($row->period_end) : null;
        if ($prefill !== null) {
            $this->statementDate = $prefill->toDateString();
        }
    }

    // A printed statement is denominated in one currency, the account's own,
    // so that is the line a multi-currency account is reconciled against —
    // never the reader's base currency, which the figure was labelled with
    // while being read off a different account's ledger.
    private function statementCurrency(ConnectionInterface $connection, int $userId, BaseCurrency $baseCurrency): string
    {
        $currency = $this->accountId === null
            ? null
            : $connection->table('accounts')
                ->where('id', $this->accountId)
                ->where('user_id', $userId)
                ->value('default_currency');

        return is_string($currency) && $currency !== '' ? $currency : $baseCurrency->code();
    }

    // Never trusts the URL-bound $accountId without re-checking: a foreign
    // or missing account returns null and render() shows nothing for it.
    private function ownedAccountId(ConnectionInterface $connection, int $userId): ?int
    {
        if ($this->accountId === null) {
            return null;
        }

        $owned = $connection->table('accounts')
            ->where('id', $this->accountId)
            ->where('user_id', $userId)
            ->exists();

        return $owned ? $this->accountId : null;
    }
}
