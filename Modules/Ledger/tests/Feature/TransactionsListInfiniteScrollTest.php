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

    // Seed 130 transactions with distinct posted_at values.
    // The query orders DESC by (posted_at, id), so we produce a predictable
    // cursor sequence: row seeded last (highest index) has the most recent
    // date and appears first in the result set. We seed with ascending dates
    // so row 130 has posted_at 2026-06-01 + 129 days = most recent.
    for ($i = 0; $i < 130; $i++) {
        $date = CarbonImmutable::parse('2025-01-01')->addDays($i)->toDateString();
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

    // Read the first-page cursor from the component's $page (via the
    // rendered view data). We need nextCursorId / nextCursorPostedAt to
    // advance the cursor precisely.
    /** @var \Modules\Ledger\Public\Dto\TransactionListPage $page1 */
    $page1 = $component->get('page');
    expect($page1->hasMore)->toBeTrue();
    expect($page1->nextCursorId)->not->toBeNull();

    // loadMore with page 1's cursor → appends page 2 (50 more rows).
    $component->call('loadMore', $page1->nextCursorId, $page1->nextCursorPostedAt);

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(100);

    // Read the page-2 cursor.
    /** @var \Modules\Ledger\Public\Dto\TransactionListPage $page2 */
    $page2 = $component->get('page');
    expect($page2->hasMore)->toBeTrue();
    expect($page2->nextCursorId)->not->toBeNull();

    // loadMore with page 2's cursor → appends the final partial page (30 rows).
    $component->call('loadMore', $page2->nextCursorId, $page2->nextCursorPostedAt);

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(130);

    /** @var \Modules\Ledger\Public\Dto\TransactionListPage $page3 */
    $page3 = $component->get('page');
    expect($page3->hasMore)->toBeFalse();
})->group('phase-4');

it('has no duplicate ids in the accumulated set after all pages loaded', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    /** @var \Modules\Ledger\Public\Dto\TransactionListPage $page1 */
    $page1 = $component->get('page');
    $component->call('loadMore', $page1->nextCursorId, $page1->nextCursorPostedAt);

    /** @var \Modules\Ledger\Public\Dto\TransactionListPage $page2 */
    $page2 = $component->get('page');
    $component->call('loadMore', $page2->nextCursorId, $page2->nextCursorPostedAt);

    /** @var array<array{id: int}> $accumulated */
    $accumulated = $component->get('accumulatedRows');
    $ids = array_column($accumulated, 'id');

    expect(count($ids))->toBe(count(array_unique($ids)));
})->group('phase-4');

it('resets accumulatedRows to a single page after toggleFullHistory', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    /** @var \Modules\Ledger\Public\Dto\TransactionListPage $page1 */
    $page1 = $component->get('page');
    $component->call('loadMore', $page1->nextCursorId, $page1->nextCursorPostedAt);

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
