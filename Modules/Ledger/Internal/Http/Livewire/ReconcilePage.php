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
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Throwable;

/**
 * `/reconcile` — the standalone SC-2 account-reconciliation surface (D-05:
 * no account-detail page exists in the app, so this is its own top-level
 * route rather than a tab on one).
 *
 * The user picks an account, confirms/edits a statement balance + date
 * (pre-filled from imported statement data per D-06), and watches the
 * cleared balance converge on that target. A non-zero difference is
 * flagged read-only (D-07) — this flow never fabricates a balancing
 * transaction. Confirming a matched reconcile calls
 * `ReconciliationWriter::completeReconcile()`, which bulk-locks the
 * account's cleared rows up to the statement date to `reconciled` (D-08).
 *
 * DI-only: no constructor. Service collaborators arrive as parameters on
 * `mount()`, `render()`, and action methods (Livewire Component subclasses
 * are barred from constructor injection by phpstan-strict-rules).
 *
 * IDOR (T-13.3-16): `$accountId` is a client-controllable, URL-bound
 * property. Every read re-validates account ownership by `user_id` before
 * touching `statement_summaries`/`card_statements`/`AccountBalanceQuery`,
 * and `ReconciliationWriter::completeReconcile()` re-scopes by `user_id`
 * again on the write side. A foreign accountId shows and does nothing.
 */
final class ReconcilePage extends Component
{
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

    /**
     * Livewire lifecycle hook — fires when the account picker changes
     * (`wire:model.live="accountId"`). Re-runs the D-06 pre-fill for the
     * newly selected account; clears any stale balance/date/error first so
     * a value pre-filled for the previous account never bleeds through.
     */
    public function updatedAccountId(mixed $value, Clock $clock, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->statementBalance = '';
        $this->statementDate = $clock->now()->toDateString();
        $this->error = '';
        $this->loadAccount($currentUser, $db);
    }

    /**
     * The "Check" affordance the blade binds to after the user edits the
     * statement balance/date. `render()` is the single source of truth for
     * the difference computation and re-runs on every Livewire round trip
     * regardless, so this method is intentionally a no-op — it exists to
     * give the UI an explicit "review the difference" action distinct from
     * live-typing, and to give tests a stable action name to call.
     */
    public function checkDiscrepancy(): void
    {
        $this->error = '';
    }

    /**
     * Completes the reconcile ONLY when the entered/pre-filled statement
     * balance exactly matches the cleared balance (difference === 0). A
     * discrepancy performs no write and creates no transaction — it is
     * surfaced as an error/flag for the user to resolve by toggling cleared
     * rows or correcting the entered balance (D-07).
     */
    public function confirmReconcile(ReconciliationWriter $writer, CurrentUser $currentUser, AccountBalanceQuery $balances): void
    {
        $this->error = '';

        if ($this->accountId === null) {
            $this->error = 'Choose an account first.';

            return;
        }

        $target = self::parseAmount($this->statementBalance);
        $date = self::parseDate($this->statementDate);

        if ($target === null || $date === null) {
            $this->error = 'Enter a valid statement balance and date.';

            return;
        }

        $user = $currentUser->user();
        $cleared = $balances->clearedBalance($this->accountId, $user);

        if ($target - $cleared !== 0) {
            // D-07: a discrepancy is flag-only — never auto-balanced, never
            // completed. No write happens below this line.
            $this->error = 'The statement balance does not match the cleared balance yet — adjust cleared rows or the entered balance until the difference is zero.';

            return;
        }

        try {
            $writer->completeReconcile($user, $this->accountId, $date);
        } catch (InvalidArgumentException) {
            // Foreign/missing accountId (IDOR) — silent no-op, mirrors the
            // cross-user convention used by PotWriter::archive et al.
            return;
        }

        $this->dispatch('toast', message: 'Reconcile complete — rows locked.');
    }

    public function render(CurrentUser $currentUser, DatabaseManager $db, ViewFactory $views, AccountBalanceQuery $balances): View
    {
        $user = $currentUser->user();
        $connection = $db->connection();

        $accounts = $connection->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'kind']);

        $ownedAccountId = $this->ownedAccountId($connection, $user->id);

        $clearedBalanceMinor = $ownedAccountId !== null
            ? $balances->clearedBalance($ownedAccountId, $user)
            : 0;

        $hasTarget = trim($this->statementBalance) !== '';
        $statementTargetMinor = self::parseAmount($this->statementBalance);
        $differenceMinor = ($ownedAccountId !== null && $hasTarget && $statementTargetMinor !== null)
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
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Reconcile · beatrax']);

        return $view;
    }

    /**
     * Pre-fills `statementBalance`/`statementDate` from the D-06 statement
     * sources for the currently-selected account:
     *   - `asn` accounts    -> latest `statement_summaries.closing_balance_minor`/`closing_balance_date`
     *   - `ics_card` accounts -> latest `card_statements.total_amount_minor` by `period_end` DESC
     *     (Pitfall 3 — NOT `open_balance_minor`; this table is READ-ONLY
     *     here, the sole legal mutator remains Chains
     *     CardStatementStateMachine, T-13.3-18)
     *   - any other kind (paypal, generic CSV, cash book) -> no statement
     *     source; fields stay blank for manual entry
     *
     * Re-validates account ownership before reading anything (T-13.3-16 /
     * T-13.3-17) — a foreign accountId is cleared back to null so no data
     * from another user's statements can leak through.
     */
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

        if ($kind === 'asn') {
            $row = $connection->table('statement_summaries')
                ->where('user_id', $user->id)
                ->where('account_id', $this->accountId)
                ->whereNotNull('closing_balance_minor')
                ->orderByDesc('period_end')
                ->orderByDesc('id')
                ->first(['closing_balance_minor', 'closing_balance_date', 'period_end']);

            if ($row !== null) {
                $this->statementBalance = self::formatMinorForInput(self::toInt($row->closing_balance_minor));
                $rawDate = $row->closing_balance_date ?? $row->period_end ?? null;
                if (is_string($rawDate) && $rawDate !== '') {
                    $this->statementDate = CarbonImmutable::parse($rawDate)->toDateString();
                }
            }

            return;
        }

        if ($kind === 'ics_card') {
            $row = $connection->table('card_statements')
                ->where('user_id', $user->id)
                ->where('account_id', $this->accountId)
                ->whereNotNull('total_amount_minor')
                ->orderByDesc('period_end')
                ->orderByDesc('id')
                ->first(['total_amount_minor', 'period_end']);

            if ($row !== null) {
                $this->statementBalance = self::formatMinorForInput(self::toInt($row->total_amount_minor));
                if (is_string($row->period_end) && $row->period_end !== '') {
                    $this->statementDate = CarbonImmutable::parse($row->period_end)->toDateString();
                }
            }
        }

        // Any other kind (paypal, generic CSV, cash book): no statement
        // source exists — leave statementBalance blank for manual entry.
    }

    /**
     * Re-validates ownership on every render (T-13.3-16 IDOR guard) — never
     * trusts the URL-bound `$accountId` without re-checking. Returns null
     * for a foreign or missing account so `render()` shows nothing for it.
     */
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

    private static function parseDate(string $raw): ?CarbonImmutable
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmed)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Formats minor units back into an editable Dutch-decimal string (e.g.
     * `-6000` -> `"-60,00"`) so a pre-filled statement balance round-trips
     * through `parseAmount()` unchanged if the user submits it untouched.
     */
    private static function formatMinorForInput(int $minor): string
    {
        $negative = $minor < 0;
        $abs = abs($minor);
        $whole = intdiv($abs, 100);
        $cents = $abs % 100;

        return ($negative ? '-' : '').$whole.','.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Parses a signed money string to minor units. Adapted from
     * `EnvelopeWriter::parseAmount()` (PATTERNS.md Shared Patterns) with one
     * deliberate change: this accepts an optional leading `-` sign and
     * allows zero/negative results. A statement balance is a signed figure
     * (an ICS card statement total is negative — the amount owed), unlike
     * an envelope amount, which is always a positive magnitude. Otherwise
     * identical: accepts plain ("12.50"), Dutch grouped ("1.234,56"), and
     * comma-decimal ("12,50") forms; the rightmost of '.' or ',' is the
     * decimal separator.
     */
    private static function parseAmount(string $value): ?int
    {
        $trimmed = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($trimmed === '') {
            return null;
        }

        $negative = str_starts_with($trimmed, '-');
        $unsigned = $negative ? substr($trimmed, 1) : $trimmed;

        $lastDot = strrpos($unsigned, '.');
        $lastComma = strrpos($unsigned, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $unsigned = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $unsigned)
                : str_replace(',', '', $unsigned);
        } elseif ($lastComma !== false) {
            $unsigned = str_replace(',', '.', $unsigned);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $unsigned) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $negative ? -$minor : $minor;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
