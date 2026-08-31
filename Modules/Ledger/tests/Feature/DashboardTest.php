<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('redirects to /imports/new on first run (zero transactions)', function (): void {
    $response = $this->get('/');

    $response->assertRedirect('/imports/new');
});

it('renders the calm dashboard with totals and recent rows when populated', function (): void {
    /** @var Category $groceries */
    $groceries = Category::create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'counterparty_name' => 'Employer NV',
        'counterparty_normalized' => 'employer nv',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -5000,
        'posted_at' => '2026-05-06',
        'booked_at' => '2026-05-06 12:00:00',
        'counterparty_name' => 'AH Amsterdam',
        'counterparty_normalized' => 'ah amsterdam',
        'category_id' => $groceries->id,
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-07',
        'booked_at' => '2026-05-07 12:00:00',
        'counterparty_name' => 'Cafe Local',
        'counterparty_normalized' => 'cafe local',
    ]);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('May 2026');
    $response->assertSee('Recent transactions', false);
    $response->assertSee('Top spending', false);
    $response->assertSee('Employer NV');
    $response->assertSee('AH Amsterdam');
    $response->assertSee('Groceries');
});

it('renders the uncategorized count badge in the top nav', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -2000,
        'posted_at' => '2026-05-06',
        'booked_at' => '2026-05-06 12:00:00',
    ]);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Uncategorized', false);
    $response->assertSeeText('2');
});
