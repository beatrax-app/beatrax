<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\AccountBalanceQuery;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->fixture = base_path('Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv');
});

// PayPal books each leg of a conversion in the direction ITS OWN balance moved,
// so the euro leg funding an outgoing dollar payment is a credit. Reading that
// leg verbatim settled a $22,50 expense as €20,80 of income.

it('settles the USD payment as an expense in euro, not as income', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    /** @var Transaction|null $row */
    $row = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('currency', 'USD')
        ->first();

    expect($row)->not->toBeNull();
    /** @var Transaction $row */
    expect($row->amount_minor)->toBe(-2250);
    expect($row->settled_currency)->toBe('EUR');
    expect($row->settled_amount_minor)->toBe(-2080);
})->group('phase-4');

it('never stores a negative exchange rate', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    /** @var Transaction $row */
    $row = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('currency', 'USD')
        ->firstOrFail();

    expect((string) $row->fx_rate_used)->toBe('0.92444444');
})->group('phase-4');

it('leaves no row whose settled leg disagrees in sign with its native leg', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    $disagreeing = Transaction::query()
        ->where('user_id', $this->fixtureUser->id)
        ->whereNotNull('fx_rate_used')
        ->get()
        ->filter(static fn (Transaction $row): bool => str_starts_with((string) $row->fx_rate_used, '-')
            || ($row->amount_minor < 0) !== ($row->settled_amount_minor < 0))
        ->pluck('id')
        ->all();

    expect($disagreeing)->toBe([]);
})->group('phase-4');

it('reads the wallet as overdrawn by 12.99, not in credit by 28.61', function (): void {
    $this->importer->runAndConfirm($this->fixture, 'paypal-csv', $this->fixtureUser);

    /** @var Account $wallet */
    $wallet = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'PAYPAL')
        ->firstOrFail();

    $balance = $this->app->make(AccountBalanceQuery::class)
        ->currentBalanceAsOf($wallet->id, $this->fixtureUser, CarbonImmutable::now())
        ->in('EUR');

    expect($balance)->toBe(-1299);
})->group('phase-4');
