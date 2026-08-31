<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;

// posted_at is a DATE column and ?before= was bound against it as a plain
// string, so '2026' was compared one character at a time: every row dated 2026
// sorted after it, the list came back empty, and the only thing on screen was a
// chip reading "Before 2026" and a badge counting one active filter. The mirror
// ?after=2026 kept all of them, and ?before=2026-1-5 kept rows dated after the
// 5th of January. Three answers, none of them the reader's question, none of
// them saying so.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $run = $this->makeImportRun($this->fixtureUser);
    $runId = (int) $run->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    foreach (['2026-04-02', '2026-05-17', '2026-06-01'] as $index => $day) {
        $db->connection()->table('transactions')->insert([
            'user_id' => $this->fixtureUser->id,
            'account_id' => $account->id,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'junk-date-'.$day),
            'fingerprint_version' => 3,
            'posted_at' => $day,
            'booked_at' => $day.' 00:00:00',
            'value_date' => $day,
            'type' => 'expense',
            'amount_minor' => -1000 - $index,
            'currency' => 'EUR',
            'settled_amount_minor' => -1000 - $index,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Junk Date Merchant',
            'counterparty_normalized' => 'junk date merchant',
            'normalization_version' => 1,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function junkDateRowCount(string $property, string $value): int
{
    $component = Livewire::withQueryParams([])
        ->test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set($property, $value);

    /** @var list<array{postedAt: string}> $rows */
    $rows = $component->get('accumulatedRows');

    return count($rows);
}

it('lists all three rows for a bound the picker could actually have sent', function (): void {
    expect(junkDateRowCount('filterBefore', '2026-12-31'))->toBe(3);
    expect(junkDateRowCount('filterAfter', '2026-01-01'))->toBe(3);
    expect(junkDateRowCount('filterBefore', '2026-05-17'))->toBe(2);
    expect(junkDateRowCount('filterAfter', '2026-05-17'))->toBe(2);
});

// The whole defect in one line each: every value here used to change the list,
// and none of them is a date. Three rows is what the unfiltered page lists, so
// this is the same assertion the URL contract makes — the page a bad link
// reaches is the page with no parameter on it.
it('lists the same three rows for a bound that is not a date', function (string $value): void {
    expect(junkDateRowCount('filterBefore', $value))->toBe(3);
    expect(junkDateRowCount('filterAfter', $value))->toBe(3);
})->with(['2026', '2026-1-5', 'tomorrow', '2026-02-30', '2026-13-01', 'abcdefg']);

// Being dropped is only half of it. The chip prints the value back and the badge
// counts it, so a filter the query never applied still told the reader one was.
it('claims no active filter and prints no chip for a bound it dropped', function (): void {
    $component = Livewire::withQueryParams(['before' => '2026', 'after' => 'tomorrow'])
        ->test(TransactionsList::class)
        ->set('currency', 'eur_only');

    expect($component->get('filterBefore'))->toBe('');
    expect($component->get('filterAfter'))->toBe('');
    expect($component->instance()->isSearchActive())->toBeFalse();
    expect($component->instance()->activeFilterCount())->toBe(0);
    expect($component->html())->not->toContain('Before 2026');
});

it('still claims the filter, and counts it, for a bound it kept', function (): void {
    $component = Livewire::withQueryParams(['before' => '2026-05-17'])
        ->test(TransactionsList::class)
        ->set('currency', 'eur_only');

    expect($component->get('filterBefore'))->toBe('2026-05-17');
    expect($component->instance()->isSearchActive())->toBeTrue();
    expect($component->instance()->activeFilterCount())->toBe(1);
});
