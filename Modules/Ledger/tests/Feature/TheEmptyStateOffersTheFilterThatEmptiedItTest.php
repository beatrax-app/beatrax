<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;

// "Nothing matches. Remove a filter to see more." is a prompt about a control,
// and the strip under it is the control. A filter in force that the strip does
// not draw leaves the reader reading an instruction they cannot follow.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-14 12:00:00'));

    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    // One categorised row, so the ledger is not empty and the list is genuinely
    // answering the filter rather than answering an empty account.
    $this->makeTransaction($this->fixtureUser, $account, $this->makeImportRun($this->fixtureUser), [
        'posted_at' => '2026-06-10',
        'booked_at' => '2026-06-10 12:00:00',
        'category_id' => Category::create([
            'user_id' => null,
            'name' => 'Groceries esf',
            'slug' => 'esf-'.bin2hex(random_bytes(4)),
            'kind' => 'expense',
            'display_order' => 1,
        ])->id,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function esfChipStrip(string $html): string
{
    $found = PatternScan::all('/<div class="srch-no-results__chips">(.*?)<\/div>\s*@?/s', $html);

    return $found[1][0] ?? '';
}

it('draws a chip for the no-category filter that emptied the list', function (): void {
    $html = Livewire::test(TransactionsList::class)
        ->set('filterUncategorized', true)
        ->html();

    expect($html)->toContain('srch-no-results')
        ->and($html)->toContain('srch-no-results__chips');

    expect(PatternScan::count('/srch-chip--active/', esfChipStrip($html)))
        ->toBeGreaterThan(0, 'the empty state asks the reader to remove a filter and offers none');
});

it('draws a chip for the type filter a report drill-down sends', function (): void {
    $html = Livewire::test(TransactionsList::class)
        ->set('filterTypes', ['refund'])
        ->set('filterAfter', '2026-06-01')
        ->set('filterBefore', '2026-06-02')
        ->html();

    expect($html)->toContain('srch-no-results__chips');

    $strip = esfChipStrip($html);
    expect(PatternScan::count('/srch-chip--active/', $strip))
        ->toBe(2, 'the date filter and the type filter are both in force and both have to be offered')
        ->and(str_contains($strip, 'Refund'))
        ->toBeTrue('the type chip does not name the type it holds');
});

// The count and the query have to read one filter. Cleaned only on the way to
// the matcher, a word the address bar invented made the badge claim a narrowing
// the list had never applied.
it('counts no filter for a type the vocabulary does not hold', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('filterTypes', ['not-a-type']);

    expect($component->get('filterTypes'))->toBe([])
        ->and($component->call('activeFilterCount')->effects['returns'][0] ?? null)->toBe(0);
});

it('draws a chip for the counterparty filter, which only the address bar can set', function (): void {
    $html = Livewire::test(TransactionsList::class)
        ->set('filterCounterparties', [999_999])
        ->html();

    expect($html)->toContain('srch-no-results__chips');

    expect(PatternScan::count('/srch-chip--active/', esfChipStrip($html)))
        ->toBe(1, 'the counterparty filter is in force and the strip offers nothing to remove');
});

// The shape, not the instance: the counterparty filter was counted and undrawn
// because nothing asked this of every filter at once. Each is set alone, so a
// chip drawn for a different filter cannot stand in for the missing one.
it('draws a chip for every filter it counts, one filter at a time', function (string $property, mixed $value): void {
    $component = Livewire::test(TransactionsList::class)->set($property, $value);

    $counted = $component->call('activeFilterCount')->effects['returns'][0] ?? 0;
    expect($counted)->toBe(1, $property.' is not counted as an active filter, so this case proves nothing');

    $html = $component->html();

    // The positive control: without it, a value that fails to empty the list
    // draws no panel, and "no chips" would read as this rule's own failure.
    expect(str_contains($html, 'srch-no-results__chips'))->toBeTrue(
        $property.' left rows on the page, so this case never reached the empty state it is about',
    );

    expect(PatternScan::count('/srch-chip--active/', esfChipStrip($html)))
        ->toBeGreaterThan(0, $property.' narrows the list and the strip draws nothing the reader can remove');
})->with([
    'accounts' => ['filterAccounts', [999_999]],
    'categories' => ['filterCategories', [999_999]],
    'uncategorized' => ['filterUncategorized', true],
    'counterparties' => ['filterCounterparties', [999_999]],
    'dates' => ['filterAfter', '2026-07-01'],
    'amount' => ['filterAmountMin', '999999'],
    'types' => ['filterTypes', ['refund']],
]);
