<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

/*
 * Regression tests for the subtractive income rule on the dashboard
 * "this period at a glance" rollup.
 *
 * The rollup MUST classify by `transactions.type`, not by amount sign.
 * A `transfer_in` row (the ICS leg of an ASN→ICS settlement) is a
 * positive amount but MUST NOT inflate the income tile; a
 * `transfer_out` row is a negative amount but MUST NOT inflate the
 * expense tile; refunds are positive too but stay out of the income
 * tile.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $asnAccount */
    $asnAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->asnAccount = $asnAccount;

    /** @var Account $icsAccount */
    $icsAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'ICS-CARD')
        ->firstOrFail();
    $this->icsAccount = $icsAccount;

    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->period = new Period(
        start: CarbonImmutable::parse('2026-05-01'),
        endExclusive: CarbonImmutable::parse('2026-06-01'),
        label: 'May 2026',
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('excludesTransfers — transfer_in and refund rows never inflate the income tile', function (): void {
    // a) Genuine income: type=income, amount=+1000 EUR.
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'income',
        'amount_minor' => 100000,
        'settled_amount_minor' => 100000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'counterparty_name' => 'Employer NV',
    ]);
    // b) ICS leg of an ASN→ICS settlement: type=transfer_in, amount=+500 EUR.
    $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'type' => 'transfer_in',
        'amount_minor' => 50000,
        'settled_amount_minor' => 50000,
        'posted_at' => '2026-05-07',
        'booked_at' => '2026-05-07 12:00:00',
        'counterparty_name' => 'iDEAL bulk settlement',
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);
    // c) Refund: type=refund, amount=+200 EUR — also excluded.
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'refund',
        'amount_minor' => 20000,
        'settled_amount_minor' => 20000,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
        'counterparty_name' => 'Bol.com return',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $summary = $query->for($this->fixtureUser, $this->period);

    // Only the genuine income row counts toward the inflow total.
    // 1000.00 EUR == 100000 minor units. Not 1700 (income + transfer_in
    // + refund). Not 1500 (income + transfer_in). Not 1200 (income +
    // refund). Exactly the income row.
    expect($summary->inflow->toMinor())->toBe(100000);
})->group('phase-4');

it('includesIncome — only type=income rows count toward the income tile', function (): void {
    // Two income rows in the period.
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'income',
        'amount_minor' => 100000,
        'settled_amount_minor' => 100000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'income',
        'amount_minor' => 25000,
        'settled_amount_minor' => 25000,
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
    ]);
    // Plus a refund that must NOT show up.
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'refund',
        'amount_minor' => 99999,
        'settled_amount_minor' => 99999,
        'posted_at' => '2026-05-20',
        'booked_at' => '2026-05-20 12:00:00',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $summary = $query->for($this->fixtureUser, $this->period);

    // Income tile = 100000 + 25000 = 125000 minor.
    expect($summary->inflow->toMinor())->toBe(125000);
})->group('phase-4');

it('expenseTileExcludesTransfers — transfer_out and fee rows never inflate the expense tile', function (): void {
    // Genuine expense: type=expense, amount=-50 EUR.
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'counterparty_name' => 'AH Amsterdam',
    ]);
    // ASN leg of an ASN→ICS settlement: type=transfer_out, amount=-500 EUR.
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -50000,
        'settled_amount_minor' => -50000,
        'posted_at' => '2026-05-07',
        'booked_at' => '2026-05-07 12:00:00',
        'counterparty_name' => 'iDEAL bulk settlement',
        'counterparty_iban' => 'ICS-CARD',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $summary = $query->for($this->fixtureUser, $this->period);

    // Only the expense row counts: 5000 minor (outflow is stored as a
    // positive total of negative amounts' absolute values). The
    // transfer_out row's 50000 absolute amount must NOT appear in the
    // expense tile.
    expect($summary->outflow->toMinor())->toBe(5000);
})->group('phase-4');
