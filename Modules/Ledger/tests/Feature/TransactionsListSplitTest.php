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

/*
 * Feature tests for the split-row expand/collapse display on the
 * /transactions list (Phase 13.1 Plan 06, Req 2). Proves:
 *  - a split transaction renders as exactly ONE top-level row bearing
 *    "Split · N" and the parent total (D-01);
 *  - both leg sub-rows are present server-side (read-only; visibility
 *    toggling is client-side and out of scope for a server test);
 *  - an unsplit transaction still renders its InlineCategoryPicker
 *    (no regression);
 *  - the legs batch-load issues a single bounded query regardless of how
 *    many split transactions are on the page (N+1 guard).
 */

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

    // Seed a split €80 -> €60 Groceries / €20 Household transaction for the
    // fixture user. A closure (not a global function) so it can reach the
    // protected TestCase::makeTransaction() helper via $this-binding.
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

    // Exactly one top-level row for the split parent (the row link marker
    // is only ever rendered once per transaction, on the parent row).
    expect(substr_count($html, 'data-testid="tx-row-link-'.$tx->id.'"'))->toBe(1);

    // The split badge shows the leg count and the desktop table's Category
    // <td> no longer renders the InlineCategoryPicker for this row.
    expect(substr_count($html, 'data-testid="split-badge-'.$tx->id.'"'))->toBe(1);
    $component->assertSee('Split · 2');

    // Amount column shows the PARENT total, never a client-recomputed sum.
    // Money::format('nl_NL') uses a NON-BREAKING space (U+00A0) between the
    // symbol and the figure — build the expected string the same way rather
    // than hardcoding a regular space, which would never match.
    $component->assertSeeHtml(Money::ofMinor(-8000, 'EUR')->format('nl_NL'));
})->group('phase-13.1');

it('renders both leg sub-rows server-side (read-only) for a split transaction', function (): void {
    $tx = ($this->seedSplitTransaction)('2026-07-01');

    $component = Livewire::test(TransactionsList::class);

    // Both leg category names + amounts + the leg note are present in the
    // rendered DOM — server-rendered always, regardless of client-side
    // expand/collapse state (Alpine x-show only hides them visually).
    $component->assertSee('Groceries');
    $component->assertSee('Household');
    $component->assertSeeHtml(Money::ofMinor(-6000, 'EUR')->format('nl_NL'));
    $component->assertSeeHtml(Money::ofMinor(-2000, 'EUR')->format('nl_NL'));
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
    // The InlineCategoryPicker child component renders its category <select>
    // for this row (keyed uniquely per transaction id).
    expect($html)->toContain('cat-picker-'.$unsplitTx->id);
})->group('phase-13.1');

it('batch-loads split legs in a single bounded query regardless of page size (no N+1)', function (): void {
    // Two independent split transactions on the same page.
    ($this->seedSplitTransaction)('2026-07-01');
    ($this->seedSplitTransaction)('2026-07-02');
    // Plus an unsplit row.
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

    // Exactly ONE query loads legs for the whole page batch, no matter how
    // many split transactions are present (Pitfall 1 / N+1 guard).
    expect($splitQueries)->toHaveCount(1);

    $connection->disableQueryLog();
})->group('phase-13.1');
