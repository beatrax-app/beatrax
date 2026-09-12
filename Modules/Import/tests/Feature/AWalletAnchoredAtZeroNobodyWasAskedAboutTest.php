<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Public\Contracts\AnchorsStartingBalanceFromStatements;
use Modules\Ledger\Public\Enums\ImportRunStatus;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->paypalFixture = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
});

function theFixtureWallet(User $user): Account
{
    return Account::query()
        ->where('user_id', $user->id)
        ->where('kind', 'paypal')
        ->firstOrFail();
}

// A PayPal export prints no opening balance, so the adapter opens the period at
// zero and closes it at the sum of the rows. Anchoring on that zero is the same
// figure the detector refuses to offer, written without anyone being asked.
it('does not anchor the wallet on an opening balance the export never printed', function (): void {
    $this->importer->runAndConfirm(
        $this->paypalFixture,
        'paypal-csv',
        $this->fixtureUser,
        'paypal-sample-1.csv',
    );

    $wallet = theFixtureWallet($this->fixtureUser);

    expect($wallet->starting_balance_minor)->toBeNull();
    expect($wallet->starting_balance_date)->toBeNull();
});

// Without this the test above passes for the wrong reason the day the summary
// stops being written at all, or stops being single-currency.
it('records the summed figures it declines to anchor on', function (): void {
    $this->importer->runAndConfirm(
        $this->paypalFixture,
        'paypal-csv',
        $this->fixtureUser,
        'paypal-sample-1.csv',
    );

    $summary = StatementSummary::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('account_id', theFixtureWallet($this->fixtureUser)->id)
        ->firstOrFail();

    expect($summary->opening_balance_minor)->toBe(0);
    expect($summary->opening_balance_currency)->toBe('EUR');
    expect($summary->opening_balance_date)->not->toBeNull();
    expect($summary->balances_derived_from_rows)->toBeTrue();
});

it('still anchors an account from a CAMT.053 statement that printed its own opening balance', function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();

    $this->importer->runAndConfirm(
        base_path('tests/fixtures/asn-camt053-sample-1.xml'),
        'camt053',
        $this->fixtureUser,
        'asn-camt053-sample-1.xml',
    );

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    expect($account->starting_balance_minor)->toBe(215891);
    expect($account->starting_balance_date)->not->toBeNull();
});

it('still anchors an account from an MT940 statement that printed its own opening balance', function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();

    $this->importer->runAndConfirm(
        base_path('tests/fixtures/asn-mt940-sample-1.sta'),
        'mt940',
        $this->fixtureUser,
        'asn-mt940-sample-1.sta',
    );

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    expect($account->starting_balance_minor)->toBe(100000);
});

// Both shapes in one pass, so a change that stopped anchoring altogether cannot
// be mistaken for the refusal this adds.
it('anchors the read summary and leaves the summed one alone in the same pass', function (): void {
    $read = Account::query()->create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Read from the file',
        'slug' => 'read-from-the-file',
        'kind' => 'bank',
        'iban' => 'NL64ASNB0000000301',
        'default_currency' => 'EUR',
    ]);

    $summed = theFixtureWallet($this->fixtureUser);

    foreach ([[$read, 250000, false], [$summed, 0, true]] as [$account, $minor, $derived]) {
        $run = ImportRun::query()->create([
            'user_id' => $this->fixtureUser->id,
            'source_format' => $derived ? 'paypal-csv' : 'camt053',
            'raw_file_path' => '/tmp/'.$account->slug,
            'sha256' => hash('sha256', $account->slug),
            'uploaded_at' => CarbonImmutable::parse('2026-03-01 12:00:00'),
            'status' => ImportRunStatus::Confirmed->value,
        ]);

        StatementSummary::query()->create([
            'user_id' => $this->fixtureUser->id,
            'import_run_id' => $run->id,
            'account_id' => $account->id,
            'iban_owner' => $account->iban,
            'opening_balance_minor' => $minor,
            'opening_balance_currency' => 'EUR',
            'opening_balance_date' => CarbonImmutable::parse('2026-02-01 00:00:00'),
            'balances_derived_from_rows' => $derived,
        ]);
    }

    $anchored = $this->app->make(AnchorsStartingBalanceFromStatements::class)
        ->anchorForUser($this->fixtureUser);

    expect($anchored)->toBe(1);
    expect($read->refresh()->starting_balance_minor)->toBe(250000);
    expect($summed->refresh()->starting_balance_minor)->toBeNull();
});
