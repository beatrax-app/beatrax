<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
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

it('defaults to the recent window (90 days) and excludes older rows', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -2000,
        'posted_at' => '2026-02-25',
        'booked_at' => '2026-02-25 12:00:00',
    ]);
    // Just outside the 90-day window.
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -9999,
        'posted_at' => '2026-02-05',
        'booked_at' => '2026-02-05 12:00:00',
    ]);

    $page = $this->listQuery->recent($this->fixtureUser, daysBack: 90);

    expect($page->rows)->toHaveCount(2);
    expect($page->hasMore)->toBeFalse();
});

it('paginates with cursors when there are more rows than the page limit', function (): void {
    for ($i = 0; $i < 15; $i++) {
        $day = sprintf('%02d', $i + 1);
        $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'amount_minor' => -1000 - $i,
            'posted_at' => "2026-05-{$day}",
            'booked_at' => "2026-05-{$day} 12:00:00",
        ]);
    }

    $page1 = $this->listQuery->recent($this->fixtureUser, daysBack: 90, limit: 10);

    expect($page1->rows)->toHaveCount(10);
    expect($page1->hasMore)->toBeTrue();
    expect($page1->nextCursorId)->not->toBeNull();

    $page2 = $this->listQuery->recent($this->fixtureUser, daysBack: 90, cursorId: $page1->nextCursorId, limit: 10);

    expect($page2->rows)->toHaveCount(5);
    expect($page2->hasMore)->toBeFalse();
    expect($page2->nextCursorId)->toBeNull();
});

it('fullHistory returns every row regardless of date', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -2500,
        'posted_at' => '2025-05-15',
        'booked_at' => '2025-05-15 12:00:00',
    ]);

    $page = $this->listQuery->fullHistory($this->fixtureUser);

    expect($page->rows)->toHaveCount(2);
});

it('scopes the list to the requested user only', function (): void {
    /** @var User $other */
    $other = User::create([
        'username' => 'other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Other',
        'slug' => 'other',
        'kind' => 'bank',
        'iban' => 'NL08ASNB9999999999',
        'default_currency' => 'EUR',
    ]);
    $otherRun = $this->makeImportRun($other, sha: '1111111111111111111111111111111111111111111111111111111111111111');

    $this->makeTransaction($other, $otherAccount, $otherRun, [
        'amount_minor' => -50000,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);

    $page = $this->listQuery->recent($this->fixtureUser, daysBack: 90);

    expect($page->rows)->toHaveCount(1);
    expect($page->rows[0]->amount->toMinor())->toBe(-1299);
});

it('renders the /transactions page with rows in newest-first order', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
        'counterparty_name' => 'Cafe Local',
        'counterparty_normalized' => 'cafe local',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -2000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'AH Amsterdam',
        'counterparty_normalized' => 'ah amsterdam',
    ]);

    $response = $this->get('/transactions');

    $response->assertOk();
    $response->assertSee('AH Amsterdam');
    $response->assertSee('Cafe Local');
    $response->assertSeeInOrder(['AH Amsterdam', 'Cafe Local']);
});

it('renders the settled pair when a currency filter is supplied', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);
    // Native USD, settled EUR: a EUR view shows only the settled half.
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1000,
        'currency' => 'USD',
        'settled_amount_minor' => -920,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $page = $this->listQuery->recent($this->fixtureUser, daysBack: 90, currency: 'EUR');

    expect($page->rows)->toHaveCount(2);
    foreach ($page->rows as $row) {
        expect($row->amount->currency())->toBe('EUR');
    }
    // posted_at DESC, so the USD-native row comes first.
    expect($page->rows[0]->amount->toMinor())->toBe(-920);
    expect($page->rows[1]->amount->toMinor())->toBe(-1299);
});

it('renders the native pair when no currency filter is supplied', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1000,
        'currency' => 'USD',
        'settled_amount_minor' => -920,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $page = $this->listQuery->fullHistory($this->fixtureUser);

    expect($page->rows)->toHaveCount(1);
    expect($page->rows[0]->amount->currency())->toBe('USD');
    expect($page->rows[0]->amount->toMinor())->toBe(-1000);
});

it('renders the empty-state copy when no transactions match the window', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2025-01-01',
        'booked_at' => '2025-01-01 12:00:00',
    ]);

    $response = $this->get('/transactions');

    $response->assertOk();
    $response->assertSee('Nothing here for this period.');
});
