<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;

/*
 * Feature tests for the phone-width infinite-scroll accumulation on
 * /transactions. Proves that sequential loadMore() calls APPEND rows to
 * the accumulatedRows collection rather than replacing the visible page,
 * and that a fresh mount / toggleFullHistory resets the accumulated set.
 *
 * Seeded data: 130 rows at the 50-row limit = page 1 (rows 1–50),
 * page 2 (rows 51–100), page 3 (rows 101–130, partial, hasMore=false).
 * Each row has a unique posted_at (distinct dates from 2025-01-01 going
 * forward) so the (posted_at, id) cursor is well-ordered.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);

    // Seed 130 transactions with distinct posted_at values within the 90-day
    // window. CarbonImmutable is set to 2026-06-15; the recent() query filters
    // to posted_at >= 2026-03-17. We seed starting from 2026-04-01 (well inside
    // the window) so all 130 rows are visible on the default recent view.
    // Dates ascend so row 130 has the most recent date and appears first when
    // the query orders DESC by (posted_at, id).
    for ($i = 0; $i < 130; $i++) {
        $date = CarbonImmutable::parse('2026-04-01')->addDays($i)->toDateString();
        $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'amount_minor' => -100,
            'posted_at' => $date,
            'booked_at' => $date.' 12:00:00',
        ]);
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('accumulates rows across sequential loadMore calls', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    // Page 1: accumulatedRows must contain exactly 50 rows.
    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toBeArray()->toHaveCount(50);

    // The component exposes hasMore, nextCursorId, nextCursorPostedAt as
    // public properties so the sentinel and test harness can read them.
    expect($component->get('hasMore'))->toBeTrue();
    expect($component->get('nextCursorId'))->not->toBeNull();

    // loadMore reads the server-side cursor from the snapshot — no args passed.
    $component->call('loadMore');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(100);

    // Page 2 cursor.
    expect($component->get('hasMore'))->toBeTrue();
    expect($component->get('nextCursorId'))->not->toBeNull();

    // loadMore reads the server-side cursor from the snapshot — no args passed.
    $component->call('loadMore');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(130);

    expect($component->get('hasMore'))->toBeFalse();
})->group('phase-4');

it('has no duplicate ids in the accumulated set after all pages loaded', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $component->call('loadMore');
    $component->call('loadMore');

    /** @var array<array{id: int}> $accumulated */
    $accumulated = $component->get('accumulatedRows');
    $ids = array_column($accumulated, 'id');

    expect(count($ids))->toBe(count(array_unique($ids)));
})->group('phase-4');

it('resets accumulatedRows to a single page after toggleFullHistory', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $component->call('loadMore');

    // Confirm we have 100 before the reset.
    expect($component->get('accumulatedRows'))->toHaveCount(100);

    // Toggle full history → cursor resets → accumulatedRows must reset to one page.
    $component->call('toggleFullHistory');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(50);
})->group('phase-4');

it('resets accumulatedRows to a single page on a fresh mount', function (): void {
    // A brand-new mount starts on page 1 with exactly 50 accumulated rows.
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(50);
})->group('phase-4');
