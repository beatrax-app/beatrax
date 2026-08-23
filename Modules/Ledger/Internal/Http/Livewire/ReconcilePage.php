<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
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

    #[Url(except: '')]
    public ?int $accountId = null;

    public string $statementBalance = '';

    public string $statementDate = '';

    public string $error = '';

    public function mount(Clock $clock, CurrentUser $currentUser, DatabaseManager $db, ?int $accountId = null): void
    {
        if ($accountId !== null) {
            $this->accountId = $accountId;
        }

        $this->statementDate = $clock->now()->toDateString();
        $this->loadAccount($currentUser, $db);
    }

    // Clears the stale balance/date/error before re-running the pre-fill, so
    // a value from the previous account never bleeds through.
    public function updatedAccountId(mixed $value, Clock $clock, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->statementBalance = '';
        $this->statementDate = $clock->now()->toDateString();
        $this->error = '';
        $this->loadAccount($currentUser, $db);
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

        $target = MoneyInput::tryToMinor($this->statementBalance);
        $date = SafeDate::parseDayOrNull($this->statementDate);

        // Both are the same answer to the operator: the form is not yet in a
        // state this can act on, and the message says which part is missing.
        if ($this->accountId === null || $target === null || $date === null) {
            $this->error = $this->accountId === null
                ? Lang::get('ledger::reconcile.errors.choose_account')
                : Lang::get('ledger::reconcile.errors.invalid_balance_date');

            return;
        }

        $user = $currentUser->user();
        // Bound the cleared balance by the statement date so this check
        // uses the same posted_at <= $date window completeReconcile()
        // locks — the unbounded balance would flag a spurious
        // discrepancy whenever a cleared row posts after the date.
        $cleared = $balances->clearedBalanceAsOf($this->accountId, $user, $date)
            ->in($this->statementCurrency($db->connection(), $user->id, $baseCurrency));

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
        $statementDate = SafeDate::parseDayOrNull($this->statementDate);

        $statementCurrency = $this->statementCurrency($connection, $user->id, $baseCurrency);

        $clearedBalanceMinor = ($ownedAccountId !== null && $statementDate !== null)
            ? $balances->clearedBalanceAsOf($ownedAccountId, $user, $statementDate)->in($statementCurrency)
            : 0;

        $hasTarget = trim($this->statementBalance) !== '';
        $statementTargetMinor = MoneyInput::tryToMinor($this->statementBalance);
        $differenceMinor = ($ownedAccountId !== null && $statementDate !== null && $hasTarget && $statementTargetMinor !== null)
            ? $statementTargetMinor - $clearedBalanceMinor
            : null;
        $isMatched = $differenceMinor === 0;

        $view = $views->make('ledger::livewire.reconcile-page', [
            'accounts' => $accounts,
            'hasAccount' => $ownedAccountId !== null,
            'clearedBalanceMinor' => $clearedBalanceMinor,
            'statementTargetMinor' => $statementTargetMinor,
            'differenceMinor' => $differenceMinor,
            'isMatched' => $isMatched,
            'statementCurrency' => $statementCurrency,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('ledger::reconcile.page_title').' · Beatrax']);

        return $view;
    }

    // Re-validates account ownership before reading anything; a foreign
    // accountId is cleared back to null so no other user's data leaks. The
    // per-kind source mapping is on the linked architecture page.
    private function loadAccount(CurrentUser $currentUser, DatabaseManager $db): void
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

        // An account with no statement source (paypal, cash book,
        // API-connected) finds none, so statementBalance is left blank for
        // manual entry.
        if ($kind === AccountKind::IcsCard->value) {
            $this->prefillFromCardStatement($connection, $user->id);
        } else {
            $this->prefillFromStatementSummary($connection, $user->id);
        }
    }

    private function prefillFromStatementSummary(ConnectionInterface $connection, int $userId): void
    {
        $row = $connection->table('statement_summaries')
            ->where('user_id', $userId)
            ->where('account_id', $this->accountId)
            ->whereNotNull('closing_balance_minor')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first(['closing_balance_minor', 'closing_balance_date', 'period_end']);

        if ($row === null) {
            return;
        }

        $this->statementBalance = MoneyInput::formatMinor(self::toInt($row->closing_balance_minor));
        $rawDate = $row->closing_balance_date ?? $row->period_end ?? null;
        if (is_string($rawDate) && $rawDate !== '') {
            $this->statementDate = CarbonImmutable::parse($rawDate)->toDateString();
        }
    }

    private function prefillFromCardStatement(ConnectionInterface $connection, int $userId): void
    {
        $row = $connection->table('card_statements')
            ->where('user_id', $userId)
            ->where('account_id', $this->accountId)
            ->whereNotNull('total_amount_minor')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first(['total_amount_minor', 'period_end']);

        if ($row === null) {
            return;
        }

        $this->statementBalance = MoneyInput::formatMinor(self::toInt($row->total_amount_minor));
        if (is_string($row->period_end) && $row->period_end !== '') {
            $this->statementDate = CarbonImmutable::parse($row->period_end)->toDateString();
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
