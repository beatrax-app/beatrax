<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function seedSearchableTransaction(int $userId, int $accountId, int $runId, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $conn = $db->connection();

    $txData = array_merge([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'srch-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:00',
        'value_date' => '2026-01-15',
        'type' => 'expense',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'description' => 'Groceries at Albert Heijn',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    $txId = (int) $conn->table('transactions')->insertGetId($txData);

    // Mirrors SearchIndexWriter so the test does not depend on it.
    $tx = $conn->table('transactions')->where('id', $txId)->first(['counterparty_name', 'description']);
    $counterpartyName = is_string($tx?->counterparty_name) ? $tx->counterparty_name : '';
    $description = is_string($tx?->description) ? $tx->description : '';
    $body = $counterpartyName.chr(12).$description.chr(12);

    $conn->table('transaction_search_docs')->upsert(
        ['transaction_id' => $txId, 'user_id' => $userId, 'search_body' => $body],
        ['transaction_id'],
        ['user_id', 'search_body'],
    );

    $conn->statement(
        'INSERT INTO transaction_search_fts(rowid, search_body) VALUES(?, ?)',
        [$txId, $body],
    );

    return $txId;
}

it('isSearchActive is false by default', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only');

    expect($component->instance()->isSearchActive())->toBeFalse();
})->group('phase-8');

it('isSearchActive is true when query is set', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('searchQuery', 'albert');

    expect($component->instance()->isSearchActive())->toBeTrue();
})->group('phase-8');

it('isSearchActive is true when account filter is set', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('filterAccounts', [1]);

    expect($component->instance()->isSearchActive())->toBeTrue();
})->group('phase-8');

it('isSearchActive is true when date filter is set', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('filterAfter', '2026-01-01');

    expect($component->instance()->isSearchActive())->toBeTrue();
})->group('phase-8');

it('clearSearch resets all search props and cursor', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('searchQuery', 'albert')
        ->set('filterAccounts', [1])
        ->set('filterAfter', '2026-01-01')
        ->set('filterBefore', '2026-06-01')
        ->set('filterAmountMin', '10')
        ->set('filterAmountMax', '500')
        ->set('filterAmountDir', 'out');

    $component->call('clearSearch');

    expect($component->instance()->searchQuery)->toBe('');
    expect($component->instance()->filterAccounts)->toBe([]);
    expect($component->instance()->filterAfter)->toBe('');
    expect($component->instance()->filterBefore)->toBe('');
    expect($component->instance()->filterAmountMin)->toBe('');
    expect($component->instance()->filterAmountMax)->toBe('');
    expect($component->instance()->filterAmountDir)->toBe('both');
    expect($component->instance()->cursorId)->toBeNull();
    expect($component->instance()->accumulatedRows)->toBe([]);
})->group('phase-8');

it('renders search results when query is set matching a transaction', function (): void {
    $txId = seedSearchableTransaction(
        $this->fixtureUser->id,
        $this->account->id,
        $this->run->id,
        [
            'counterparty_name' => 'Jumbo Supermarkt',
            'counterparty_normalized' => 'jumbo supermarkt',
            'description' => 'Weekly groceries Jumbo',
            'posted_at' => '2026-05-01',
            'booked_at' => '2026-05-01 10:00:00',
        ]
    );

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('searchQuery', 'Jumbo');

    expect($component->instance()->isSearchActive())->toBeTrue();

    $component->assertSee('Jumbo Supermarkt');
})->group('phase-8');

it('renders no results for a query that matches nothing', function (): void {
    seedSearchableTransaction(
        $this->fixtureUser->id,
        $this->account->id,
        $this->run->id,
        [
            'counterparty_name' => 'Albert Heijn',
            'counterparty_normalized' => 'albert heijn',
            'description' => 'Groceries',
        ]
    );

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('searchQuery', 'zzz-no-match-xyz');

    expect($component->instance()->isSearchActive())->toBeTrue();

    $component->assertSee('Nothing matches');
})->group('phase-8');

it('search with filter-only (no query) returns filtered results', function (): void {
    seedSearchableTransaction(
        $this->fixtureUser->id,
        $this->account->id,
        $this->run->id,
        [
            'counterparty_name' => 'In Range Vendor',
            'counterparty_normalized' => 'in range vendor',
            'description' => 'January purchase',
            'posted_at' => '2026-01-15',
            'booked_at' => '2026-01-15 12:00:00',
        ]
    );

    seedSearchableTransaction(
        $this->fixtureUser->id,
        $this->account->id,
        $this->run->id,
        [
            'counterparty_name' => 'Out Of Range Vendor',
            'counterparty_normalized' => 'out of range vendor',
            'description' => 'May purchase',
            'posted_at' => '2026-05-15',
            'booked_at' => '2026-05-15 12:00:00',
        ]
    );

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('filterAfter', '2026-01-01')
        ->set('filterBefore', '2026-01-31');

    expect($component->instance()->isSearchActive())->toBeTrue();

    $component->assertSee('In Range Vendor');
    $component->assertDontSee('Out Of Range Vendor');
})->group('phase-8');

it('clears search and returns to default view', function (): void {
    // Older than the 90-day default window, so only search mode reaches it.
    seedSearchableTransaction(
        $this->fixtureUser->id,
        $this->account->id,
        $this->run->id,
        [
            'counterparty_name' => 'Old Vendor',
            'counterparty_normalized' => 'old vendor',
            'description' => 'Ancient purchase',
            'posted_at' => '2025-01-10',
            'booked_at' => '2025-01-10 12:00:00',
        ]
    );

    seedSearchableTransaction(
        $this->fixtureUser->id,
        $this->account->id,
        $this->run->id,
        [
            'counterparty_name' => 'Recent Vendor',
            'counterparty_normalized' => 'recent vendor',
            'description' => 'Fresh purchase',
            'posted_at' => '2026-05-20',
            'booked_at' => '2026-05-20 12:00:00',
        ]
    );

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('searchQuery', 'Old Vendor');

    $component->assertSee('Old Vendor');

    $component->call('clearSearch');

    expect($component->instance()->searchQuery)->toBe('');
    expect($component->instance()->isSearchActive())->toBeFalse();
})->group('phase-8');

it('summary strip fields are available in view when search is active', function (): void {
    seedSearchableTransaction(
        $this->fixtureUser->id,
        $this->account->id,
        $this->run->id,
        [
            'counterparty_name' => 'Strip Vendor',
            'counterparty_normalized' => 'strip vendor',
            'description' => 'Test for summary strip',
            'posted_at' => '2026-03-01',
            'booked_at' => '2026-03-01 12:00:00',
            'amount_minor' => -5000,
            'settled_amount_minor' => -5000,
        ]
    );

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('searchQuery', 'Strip');

    $viewData = $component->viewData('searchTotalCount');
    expect($viewData)->toBeInt()->toBeGreaterThanOrEqual(1);
})->group('phase-8');
