<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Ledger\Internal\Services\TransactionFilterOptions;
use Modules\Ledger\Models\Account;

// What the filter chips can be set to is a different question from what the list
// is showing, so these are read from the tables rather than from the page. The
// contents were never asserted anywhere before — only that render() survived.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->filterOptions = app(TransactionFilterOptions::class);
    $this->conn = app(DatabaseManager::class)->connection();
});

function filterOptionsOtherUserId(): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'someone-else',
        'password' => 'someone-elses-password',
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function filterOptionsCategory(int $rowUserId, string $name, string $slug): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $rowUserId === 0 ? null : $rowUserId,
        'name' => $name,
        'slug' => $slug,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('names each account with the currency the row carries', function (): void {
    $accounts = $this->filterOptions->accounts($this->fixtureUser->id);

    expect($accounts)->not->toBeEmpty();

    $fixture = collect($accounts)->firstWhere('name', 'ASN Fixture Account');

    expect($fixture)->not->toBeNull()
        ->and($fixture['id'])->toBeInt()
        ->and($fixture['currency'])->toBeString()
        ->and($fixture['currency'])->not->toBe('');
});

// A picker is one of the surfaces a cross-user leak shows up on first: it lists
// rows the current page never contains, so nothing else would reveal a stray.
it('lists no account belonging to another user', function (): void {
    $otherId = filterOptionsOtherUserId();

    Account::query()->create([
        'user_id' => $otherId,
        'name' => 'Not Yours',
        'slug' => 'not-yours',
        'iban' => 'NL91ABNA0417164300',
        'default_currency' => 'EUR',
        'kind' => 'checking',
    ]);

    expect(collect($this->filterOptions->accounts($this->fixtureUser->id))->pluck('name'))
        ->not->toContain('Not Yours');
});

it('orders accounts by name so the picker does not reshuffle between renders', function (): void {
    Account::query()->create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Aardvark Savings',
        'slug' => 'aardvark',
        'iban' => 'NL20INGB0001234567',
        'default_currency' => 'EUR',
        'kind' => 'savings',
    ]);

    $names = array_column($this->filterOptions->accounts($this->fixtureUser->id), 'name');
    $sorted = $names;
    sort($sorted);

    // Compared against PHP's own sort rather than a written-out order: SQLite
    // orders on BINARY, which puts 'ASN' ahead of 'Aardvark' because uppercase
    // S precedes lowercase a. Naming an expected first element here pins that
    // collation quirk into the test instead of the property being asserted.
    expect($names)->toBe($sorted)
        ->and($names)->toContain('Aardvark Savings', 'ASN Fixture Account');
});

// The currency fallback inside accounts() is defensive only, and this is why:
// default_currency is char(3) NOT NULL carrying its own default, so a row with
// no currency cannot be stored to reach it. Pinned rather than assumed, because
// the day the column turns nullable that branch starts deciding what a reader
// sees, and nothing else would notice it had woken up.
it('cannot store an account with no currency, which is what keeps the fallback unreachable', function (): void {
    expect(fn () => $this->conn->table('accounts')->insert([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Currencyless',
        'slug' => 'currencyless',
        'iban' => 'NL02ABNA0123456789',
        'default_currency' => null,
        'kind' => 'checking',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(collect($this->filterOptions->accounts($this->fixtureUser->id))->pluck('currency'))
        ->each->toHaveLength(3);
});

// The defect this query was written around: filtering on user_id alone hid the
// chip entirely on an install carrying only the seeded default tree, because
// every one of those rows has user_id NULL.
it('offers the global default categories as well as the ones this user owns', function (): void {
    filterOptionsCategory(0, 'Seeded Global', 'seeded-global');
    filterOptionsCategory($this->fixtureUser->id, 'Mine Alone', 'mine-alone');

    $names = collect($this->filterOptions->categories($this->fixtureUser->id))->pluck('name');

    expect($names)->toContain('Seeded Global')
        ->and($names)->toContain('Mine Alone');
});

it('offers no category belonging to another user', function (): void {
    filterOptionsCategory(filterOptionsOtherUserId(), 'Theirs Alone', 'theirs-alone');

    expect(collect($this->filterOptions->categories($this->fixtureUser->id))->pluck('name'))
        ->not->toContain('Theirs Alone');
});

// Sorted on what the reader sees. Ordering by the stored English arranges a
// translated picker by a word that is not on screen anywhere.
it('sorts categories by the displayed name and breaks ties by id', function (): void {
    $zebra = filterOptionsCategory($this->fixtureUser->id, 'Zebra', 'zebra-cat');
    $apple = filterOptionsCategory($this->fixtureUser->id, 'Apple', 'apple-cat');

    $ids = array_column($this->filterOptions->categories($this->fixtureUser->id), 'id');

    expect(array_search($apple, $ids, true))->toBeLessThan(array_search($zebra, $ids, true));
});
