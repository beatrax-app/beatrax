<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\TransactionListQuery;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
    $this->listQuery = $this->app->make(TransactionListQuery::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns secondaryAmount when row is FX and currency filter is null', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $page = $this->listQuery->fullHistory($this->fixtureUser);

    expect($page->rows)->toHaveCount(1);
    $row = $page->rows[0];
    expect($row->amount->currency())->toBe('USD');
    expect($row->amount->toMinor())->toBe(-1299);
    expect($row->secondaryAmount)->not->toBeNull();
    expect($row->secondaryAmount->currency())->toBe('EUR');
    expect($row->secondaryAmount->toMinor())->toBe(-1207);
})->group('phase-3');

it('returns null secondaryAmount for EUR-native row when currency filter is null', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $page = $this->listQuery->fullHistory($this->fixtureUser);

    expect($page->rows)->toHaveCount(1);
    $row = $page->rows[0];
    expect($row->amount->currency())->toBe('EUR');
    expect($row->secondaryAmount)->toBeNull();
})->group('phase-3');

it('returns null secondaryAmount when currency filter is EUR even for FX row', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $page = $this->listQuery->fullHistory($this->fixtureUser, currency: 'EUR');

    expect($page->rows)->toHaveCount(1);
    $row = $page->rows[0];
    expect($row->amount->currency())->toBe('EUR');
    expect($row->amount->toMinor())->toBe(-1207);
    expect($row->secondaryAmount)->toBeNull();
})->group('phase-3');
