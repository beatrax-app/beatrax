<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

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
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'income',
        'amount_minor' => 100000,
        'settled_amount_minor' => 100000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'counterparty_name' => 'Employer NV',
    ]);
    // The ICS leg of an ASN→ICS settlement: a transfer_in with a positive amount.
    $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'type' => 'transfer_in',
        'amount_minor' => 50000,
        'settled_amount_minor' => 50000,
        'posted_at' => '2026-05-07',
        'booked_at' => '2026-05-07 12:00:00',
        'counterparty_name' => 'iDEAL bulk settlement',
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);
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

    // The income row alone — not 170000 with the transfer_in and refund
    // folded in, and not 150000 with only the transfer_in.
    expect($summary->inflow->toMinor())->toBe(100000);
})->group('phase-4');

it('includesIncome — only type=income rows count toward the income tile', function (): void {
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

    // 100000 + 25000.
    expect($summary->inflow->toMinor())->toBe(125000);
})->group('phase-4');

it('expenseTileExcludesTransfers — transfer_out and fee rows never inflate the expense tile', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'counterparty_name' => 'AH Amsterdam',
    ]);
    // The ASN leg of an ASN→ICS settlement.
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

    // Outflow is a positive total of absolute amounts, so the transfer_out
    // row would show up as 50000 if it were counted.
    expect($summary->outflow->toMinor())->toBe(5000);
})->group('phase-4');
