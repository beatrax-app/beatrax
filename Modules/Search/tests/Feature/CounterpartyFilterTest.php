<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

beforeEach(function (): void {
    $this->userAId = $this->searchTestUser('cpfilter-user-a');
    $this->userBId = $this->searchTestUser('cpfilter-user-b');
});

function cpFilterMakeCounterparty(int $userId, string $displayName): int
{
    $db = app(DatabaseManager::class)->connection();
    $suffix = bin2hex(random_bytes(4));

    return (int) $db->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'cpfilter-'.$suffix,
        'display_name' => $displayName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('SearchQuery restricts results to the owned counterparty', function (): void {
    $ownedId = cpFilterMakeCounterparty($this->userAId, 'Owned Counterparty');

    $matchingTxId = $this->searchTestTransaction($this->userAId, [
        'counterparty_id' => $ownedId,
        'counterparty_name' => 'Owned Counterparty',
        'description' => 'Matches the counterparty filter',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'counterparty_id' => null,
        'counterparty_name' => 'Unrelated Vendor',
        'description' => 'Should be excluded',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(counterparties: [$ownedId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(1);
    expect($page->rows[0]->id)->toBe($matchingTxId);
});

it('SearchQuery returns empty result for a foreign counterparty id', function (): void {
    $foreignId = cpFilterMakeCounterparty($this->userBId, 'Foreign Counterparty');

    $this->searchTestTransaction($this->userBId, [
        'counterparty_id' => $foreignId,
        'counterparty_name' => 'Foreign Counterparty',
        'description' => 'Belongs to user B',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'counterparty_id' => null,
        'counterparty_name' => 'User A Vendor',
        'description' => 'User A own data',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(counterparties: [$foreignId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(0);
});

it('SearchFilters isActive is true when only counterparties is set', function (): void {
    $filters = new SearchFilters(counterparties: [123]);

    expect($filters->isActive())->toBeTrue();
});

it('SearchFilters isActive is false when empty', function (): void {
    $filters = SearchFilters::empty();

    expect($filters->isActive())->toBeFalse();
});

it('TransactionsList with ?counterparty[]= enters search mode and shows only that counterparty rows', function (): void {
    $fixture = $this->seedFixtureUserAndAccount();
    $fixtureUser = $fixture['user'];
    $account = $fixture['account'];
    $this->actingAs($fixtureUser);

    $db = app(DatabaseManager::class)->connection();
    $runId = $db->table('import_runs')->insertGetId([
        'user_id' => $fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cpfilter-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'cpfilter-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cpId = cpFilterMakeCounterparty($fixtureUser->id, 'Drilldown Target');

    $matchingTxId = $this->searchTestTransaction($fixtureUser->id, [
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'counterparty_id' => $cpId,
        'counterparty_name' => 'Drilldown Target',
        'counterparty_normalized' => 'drilldown target',
        'description' => 'Should appear',
    ]);

    $this->searchTestTransaction($fixtureUser->id, [
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'counterparty_id' => null,
        'counterparty_name' => 'Other Vendor',
        'counterparty_normalized' => 'other vendor',
        'description' => 'Should not appear',
    ]);

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('filterCounterparties', [$cpId]);

    expect($component->instance()->isSearchActive())->toBeTrue();

    $component->assertSee('Drilldown Target');
    $component->assertDontSee('Other Vendor');

    expect($matchingTxId)->toBeInt();
});

it('TransactionsList clearSearch resets filterCounterparties', function (): void {
    $fixture = $this->seedFixtureUserAndAccount();
    $this->actingAs($fixture['user']);

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('filterCounterparties', [1]);

    expect($component->instance()->isSearchActive())->toBeTrue();

    $component->call('clearSearch');

    expect($component->instance()->filterCounterparties)->toBe([]);
    expect($component->instance()->isSearchActive())->toBeFalse();
});
