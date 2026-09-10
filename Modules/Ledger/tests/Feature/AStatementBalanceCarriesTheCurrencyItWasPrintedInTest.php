<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Internal\Services\BackfillStartingBalanceFromStatementSummaries;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// `statement_summaries` records which currency its opening and closing balances
// were printed in, and the three readers of those figures all took the account's
// own denomination instead. The column was written and never read.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    $this->user = User::create([
        'username' => 'statement-denomination',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Revolut',
        'slug' => 'statement-denomination-revolut',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => Currency::Eur->value,
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/statement-denomination.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-03-01 12:00:00'),
        'status' => ImportRunStatus::Confirmed->value,
    ]);

    $this->summaryIn = function (string $currency): StatementSummary {
        return StatementSummary::create([
            'user_id' => $this->user->id,
            'import_run_id' => $this->run->id,
            'account_id' => $this->account->id,
            'iban_owner' => $this->account->iban,
            'opening_balance_minor' => 100_000,
            'opening_balance_currency' => $currency,
            'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
            'closing_balance_minor' => 150_000,
            'closing_balance_currency' => $currency,
            'closing_balance_date' => CarbonImmutable::parse('2026-02-28 00:00:00'),
            'period_end' => CarbonImmutable::parse('2026-02-28 00:00:00'),
        ]);
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('anchors an account from a statement printed in its own currency', function (): void {
    ($this->summaryIn)(Currency::Eur->value);

    expect(app(BackfillStartingBalanceFromStatementSummaries::class)->anchorForUser($this->user))->toBe(1);

    expect(app(AccountStartingBalanceQuery::class)->forAccount($this->account->id, $this->user))
        ->toMatchArray(['minorUnits' => 100_000, 'currency' => Currency::Eur->value]);
});

it('never anchors an account from a statement printed in another currency', function (): void {
    ($this->summaryIn)(Currency::Usd->value);

    expect(app(BackfillStartingBalanceFromStatementSummaries::class)->anchorForUser($this->user))->toBe(0);

    expect(app(AccountStartingBalanceQuery::class)->forAccount($this->account->id, $this->user))
        ->toMatchArray(['minorUnits' => 0]);
});

it('never proposes a starting balance the account is not denominated in', function (): void {
    ($this->summaryIn)(Currency::Usd->value);

    $candidates = app(DetectStartingBalancesQuery::class)->collect([$this->run->id], $this->user);

    expect($candidates)->toBe([]);
});

it('never prefills the reconcile box with a figure the statement never printed', function (): void {
    ($this->summaryIn)(Currency::Usd->value);

    expect(Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id])->get('statementBalance'))
        ->toBe('');
});

it('prefills the reconcile box from a statement printed in the account\'s currency', function (): void {
    ($this->summaryIn)(Currency::Eur->value);

    expect(Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id])->get('statementBalance'))
        ->toBe(MoneyInput::formatMinor(150_000, Currency::Eur->value));
});
