<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

// A demo walk reported the dashboard's recent-transactions table drawing an
// empty counterparty column while /transactions showed names for the same rows.
// Both screens read TransactionListQuery and render the same DTO field, and
// neither is blank here — the report came from a session whose sign-in race its
// author retracted. Kept because the two screens agreeing is worth a guard.
it('shows the counterparty on the dashboard that the transactions list shows', function (): void {
    app(RunsImports::class)->runAndConfirm(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    // Inside the dashboard's 90-day window, which the fixture's April dates are
    // outside of by the time anyone runs this.
    Carbon::setTestNow('2026-05-02 12:00:00');
    CarbonImmutable::setTestNow('2026-05-02 12:00:00');

    $name = (string) Transaction::query()
        ->orderByDesc('posted_at')
        ->firstOrFail()
        ->counterparty_name;

    expect($name)->not->toBe('');

    $this->get('/')->assertOk()->assertSee($name, escape: false);
    $this->get('/transactions')->assertOk()->assertSee($name, escape: false);
});
