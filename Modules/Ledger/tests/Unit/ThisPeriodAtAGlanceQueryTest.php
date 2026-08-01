<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $this->periods = $this->app->make(PeriodQuery::class);
    $this->user = $this->fixtureUser;
    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns a first-run DashboardSummary when the user has zero transactions', function (): void {
    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary)->toBeInstanceOf(DashboardSummary::class);
    expect($summary->isFirstRun)->toBeTrue();
    expect($summary->inflow->toMinor())->toBe(0);
    expect($summary->outflow->toMinor())->toBe(0);
    expect($summary->net->toMinor())->toBe(0);
    expect($summary->inflow->currency())->toBe('EUR');
    expect($summary->topCategories)->toBe([]);
    expect($summary->recentTransactions)->toBe([]);
    expect($summary->uncategorizedCount)->toBe(0);
});

it('aggregates inflow / outflow / net for the current period', function (): void {
    // 5 income (+) rows in the current period
    foreach ([10000, 20000, 15000, 5000, 12500] as $amount) {
        $this->makeTransaction($this->user, $this->account, $this->run, [
            'amount_minor' => $amount,
            'posted_at' => '2026-05-05',
            'booked_at' => '2026-05-05 12:00:00',
        ]);
    }
    // 5 expense (-) rows in the current period
    foreach ([-1299, -2500, -7300, -1100, -2000] as $amount) {
        $this->makeTransaction($this->user, $this->account, $this->run, [
            'amount_minor' => $amount,
            'posted_at' => '2026-05-08',
            'booked_at' => '2026-05-08 12:00:00',
        ]);
    }

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary->isFirstRun)->toBeFalse();
    expect($summary->inflow->toMinor())->toBe(10000 + 20000 + 15000 + 5000 + 12500);
    expect($summary->outflow->toMinor())->toBe(1299 + 2500 + 7300 + 1100 + 2000);
    expect($summary->net->toMinor())->toBe(($summary->inflow->toMinor()) - ($summary->outflow->toMinor()));
    expect($summary->recentTransactions)->toHaveCount(10);
    expect($summary->topCategories)->toBe([]); // none categorized
});

it('ignores transactions outside the current period window', function (): void {
    // In-period row
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);
    // Out-of-period row (60 days ago, before period_start_day=1 of May)
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -9999,
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 12:00:00',
    ]);

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary->outflow->toMinor())->toBe(1299);
});

it('scopes totals to the queried user only (no cross-user leakage)', function (): void {
    /** @var User $other */
    $other = User::create([
        'username' => 'other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Other account',
        'slug' => 'other-account',
        'kind' => 'bank',
        'iban' => 'NL08ASNB9999999999',
        'default_currency' => 'EUR',
    ]);
    $otherRun = $this->makeImportRun($other, sha: '1111111111111111111111111111111111111111111111111111111111111111');

    // Seed other user
    $this->makeTransaction($other, $otherAccount, $otherRun, [
        'amount_minor' => -50000,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);

    // Seed current fixture user
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary->outflow->toMinor())->toBe(1299);
});

it('counts uncategorized transactions across all periods', function (): void {
    /** @var Category $groceries */
    $groceries = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    // Categorized
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'category_id' => $groceries->id,
    ]);
    // Uncategorized x 3
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -2000,
        'posted_at' => '2026-05-06',
        'booked_at' => '2026-05-06 12:00:00',
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -3000,
        'posted_at' => '2026-05-07',
        'booked_at' => '2026-05-07 12:00:00',
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -4000,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary->uncategorizedCount)->toBe(3);
});

it('returns top categories sorted by spend descending with percentageOfTotal summing to ~1', function (): void {
    /** @var Category $groceries */
    $groceries = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    /** @var Category $subs */
    $subs = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Subscriptions',
        'slug' => 'subscriptions',
        'kind' => 'expense',
        'display_order' => 2,
    ]);
    /** @var Category $transport */
    $transport = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Transport',
        'slug' => 'transport',
        'kind' => 'expense',
        'display_order' => 3,
    ]);

    // Groceries: -50,00 + -30,00 = -80,00
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -5000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'category_id' => $groceries->id,
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -3000,
        'posted_at' => '2026-05-06',
        'booked_at' => '2026-05-06 12:00:00',
        'category_id' => $groceries->id,
    ]);
    // Subscriptions: -20,00
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -2000,
        'posted_at' => '2026-05-07',
        'booked_at' => '2026-05-07 12:00:00',
        'category_id' => $subs->id,
    ]);
    // Transport: -10,00
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1000,
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
        'category_id' => $transport->id,
    ]);

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary->topCategories)->toHaveCount(3);
    expect($summary->topCategories[0]->name)->toBe('Groceries');
    expect($summary->topCategories[0]->spend->toMinor())->toBe(8000);
    expect($summary->topCategories[1]->name)->toBe('Subscriptions');
    expect($summary->topCategories[1]->spend->toMinor())->toBe(2000);
    expect($summary->topCategories[2]->name)->toBe('Transport');
    expect($summary->topCategories[2]->spend->toMinor())->toBe(1000);

    $total = array_sum(array_map(fn ($row) => $row->percentageOfTotal, $summary->topCategories));
    expect(abs($total - 1.0))->toBeLessThan(0.0001);
});

it('does not surface a foreign user\'s parent name in the breadcrumb', function (): void {
    // Foreign user owns a parent category named "Foreign Parent".
    /** @var User $foreignUser */
    $foreignUser = User::create([
        'username' => 'foreign',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    /** @var Category $foreignParent */
    $foreignParent = Category::create([
        'user_id' => $foreignUser->id,
        'name' => 'Foreign Parent',
        'slug' => 'foreign-parent',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    // Current user has a leaf whose parent_id (accidentally / via future
    // cross-user share / manual edit) points at the foreign parent.
    /** @var Category $localLeaf */
    $localLeaf = Category::create([
        'user_id' => $this->user->id,
        'parent_id' => $foreignParent->id,
        'name' => 'Local Leaf',
        'slug' => 'local-leaf',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'category_id' => $localLeaf->id,
    ]);

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary->topCategories)->toHaveCount(1);
    // Walk terminates at the filtered-out foreign parent; only the leaf
    // name appears in the breadcrumb.
    expect($summary->topCategories[0]->name)->toBe('Local Leaf');
});

it('does not probe categories beyond a filtered-out parent in the walk', function (): void {
    /** @var User $foreignUser */
    $foreignUser = User::create([
        'username' => 'foreign-walk',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    // Three-level chain: local leaf → foreign mid → (would-be) global root.
    // The foreign mid is filtered out by the visibility predicate; the
    // global root is unreachable through it.
    /** @var Category $globalRoot */
    $globalRoot = Category::create([
        'user_id' => null,
        'name' => 'Global Root',
        'slug' => 'global-root',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    /** @var Category $foreignMid */
    $foreignMid = Category::create([
        'user_id' => $foreignUser->id,
        'parent_id' => $globalRoot->id,
        'name' => 'Foreign Mid',
        'slug' => 'foreign-mid',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    /** @var Category $localLeaf */
    $localLeaf = Category::create([
        'user_id' => $this->user->id,
        'parent_id' => $foreignMid->id,
        'name' => 'Local Leaf',
        'slug' => 'local-leaf-walk',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'category_id' => $localLeaf->id,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    $categoriesQueries = array_values(array_filter(
        $connection->getQueryLog(),
        static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'from "categories"'),
    ));

    // Exactly 2 batches: the leaf (returns 1 row), then the foreign mid
    // (returns 0 rows). The global root is never enqueued because the
    // foreign mid was attempted-but-filtered.
    expect($categoriesQueries)->toHaveCount(2);
    expect($summary->topCategories)->toHaveCount(1);
    expect($summary->topCategories[0]->name)->toBe('Local Leaf');

    $connection->disableQueryLog();
});

it('renders the full category path for nested categories', function (): void {
    /** @var Category $subs */
    $subs = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Subscriptions',
        'slug' => 'subscriptions',
        'kind' => 'expense',
        'display_order' => 2,
    ]);
    /** @var Category $streaming */
    $streaming = Category::create([
        'user_id' => $this->user->id,
        'parent_id' => $subs->id,
        'name' => 'Streaming',
        'slug' => 'subscriptions-streaming',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'category_id' => $streaming->id,
    ]);

    $period = $this->periods->current();
    $summary = $this->query->for($this->user, $period);

    expect($summary->topCategories[0]->name)->toBe('Subscriptions / Streaming');
});
