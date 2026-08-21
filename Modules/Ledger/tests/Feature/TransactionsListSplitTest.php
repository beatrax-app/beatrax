<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\ValueObjects\Money;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-04 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries-tlst', 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'household-tlst', 'kind' => 'expense', 'display_order' => 2]);

    // A closure, not a global function: it needs $this-binding to reach the
    // protected makeTransaction() helper.
    $this->seedSplitTransaction = function (string $postedAt): Transaction {
        $tx = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'amount_minor' => -8000,
            'settled_amount_minor' => -8000,
            'category_id' => $this->groceries->id,
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'counterparty_name' => 'Albert Heijn',
        ]);

        app(SaveTransactionSplit::class)->save($this->fixtureUser, $tx->id, [
            ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => 'Weekly shop'],
            ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
        ]);

        return $tx;
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders a split transaction as exactly one row bearing "Split · 2" and the parent total', function (): void {
    $tx = ($this->seedSplitTransaction)('2026-07-01');

    $component = Livewire::test(TransactionsList::class);
    $html = $component->html();

    // The row-link marker renders once per transaction, on the parent row
    // only, so counting it counts top-level rows.
    expect(substr_count($html, 'data-testid="tx-row-link-'.$tx->id.'"'))->toBe(1);

    expect(substr_count($html, 'data-testid="split-badge-'.$tx->id.'"'))->toBe(1);
    $component->assertSee('Split · 2');

    // Money::format() puts a non-breaking space between symbol and figure, so
    // the expectation is built through it rather than hardcoded.
    $component->assertSeeHtml(Money::ofMinor(-8000, 'EUR')->format());
})->group('phase-13.1');

it('renders both leg sub-rows server-side (read-only) for a split transaction', function (): void {
    $tx = ($this->seedSplitTransaction)('2026-07-01');

    $component = Livewire::test(TransactionsList::class);

    // Legs are always server-rendered: Alpine's x-show only hides them.
    $component->assertSee('Groceries');
    $component->assertSee('Household');
    $component->assertSeeHtml(Money::ofMinor(-6000, 'EUR')->format());
    $component->assertSeeHtml(Money::ofMinor(-2000, 'EUR')->format());
    $component->assertSee('Weekly shop');

    $html = $component->html();
    expect(substr_count($html, 'data-testid="split-leg-'.$tx->id.'-'))->toBe(2);
})->group('phase-13.1');

it('still renders the InlineCategoryPicker (no split badge) for an unsplit transaction', function (): void {
    $unsplitTx = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'category_id' => $this->groceries->id,
        'posted_at' => '2026-07-02',
        'booked_at' => '2026-07-02 12:00:00',
        'counterparty_name' => 'Jumbo',
    ]);

    $component = Livewire::test(TransactionsList::class);
    $html = $component->html();

    expect($html)->not->toContain('data-testid="split-badge-'.$unsplitTx->id.'"');
    // `cat-picker-<id>` is the InlineCategoryPicker child's per-row key.
    expect($html)->toContain('cat-picker-'.$unsplitTx->id);
})->group('phase-13.1');

it('batch-loads split legs in a single bounded query regardless of page size (no N+1)', function (): void {
    ($this->seedSplitTransaction)('2026-07-01');
    ($this->seedSplitTransaction)('2026-07-02');
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -500,
        'posted_at' => '2026-07-03',
        'booked_at' => '2026-07-03 12:00:00',
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    Livewire::test(TransactionsList::class);

    $splitQueries = array_values(array_filter(
        $connection->getQueryLog(),
        static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'from "transaction_splits"'),
    ));

    expect($splitQueries)->toHaveCount(1);

    $connection->disableQueryLog();
})->group('phase-13.1');
