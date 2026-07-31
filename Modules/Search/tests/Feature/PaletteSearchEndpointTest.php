<?php

declare(strict_types=1);
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Search\Internal\Http\Livewire\PaletteSearchEndpoint;

// Plan 05 — PaletteSearchEndpointTest GREEN (SRCH-02 palette endpoint)

/**
 * PaletteSearchEndpointTest — SRCH-02 palette endpoint test.
 *
 * Tests that PaletteSearchEndpoint::search() returns user-scoped,
 * capped-at-5 transaction hits when the query length >= 2 chars (D-01).
 *
 * The component is registered by SearchServiceProvider's class_exists()-
 * guarded block as 'search.palette-search-endpoint'. Now that
 * PaletteSearchEndpoint exists, the guard is live and the component
 * is mountable.
 */

// SRCH-02: palette endpoint returns top-5 transaction hits
it('it_returns_top_five_transaction_hits', function (): void {
    // Authenticate a user so CurrentUser resolves in the component action.
    $user = User::find($this->searchTestUser('palette-user-a'));
    $this->actingAs($user);

    // Seed 7 transactions matching the query — endpoint must cap at 5
    for ($i = 1; $i <= 7; $i++) {
        $this->searchTestTransaction($user->id, [
            'counterparty_name' => "Heijn Supermarkt {$i}",
            'description' => "Weekly shop number {$i}",
        ]);
    }

    // The PaletteSearchEndpoint is a Livewire component registered under
    // 'search.palette-search-endpoint'. The class_exists() guard in
    // SearchServiceProvider is now live since Plan 05 creates the class.
    $component = Livewire::test(PaletteSearchEndpoint::class);

    // Act — call the search action with a query that matches all 7 seeds
    $component->call('search', 'Heijn');

    // Assert — top-5 cap enforced by SearchResultsProviderImpl → SearchQuery::palette()
    $hits = $component->get('transactionHits');
    expect($hits)->toHaveCount(5);
});

// A query shorter than the minimum length clears all sections without
// touching the search provider.
it('it_clears_results_for_a_too_short_query', function (): void {
    $user = User::find($this->searchTestUser('palette-user-short'));
    $this->actingAs($user);
    $this->searchTestTransaction($user->id, ['counterparty_name' => 'Heijn Supermarkt', 'description' => 'weekly shop']);

    $component = Livewire::test(PaletteSearchEndpoint::class);
    $component->call('search', 'Heijn');
    expect($component->get('transactionHits'))->not->toBeEmpty();

    $component->call('search', 'H');

    expect($component->get('transactionHits'))->toBe([])
        ->and($component->get('entityHits'))->toBe([])
        ->and($component->get('totalCount'))->toBe(0);
});

// Entity sections are normalized into the component's typed entityHits
// shape alongside the transaction hits.
it('it_returns_normalized_entity_hits', function (): void {
    $user = User::find($this->searchTestUser('palette-user-entity'));
    $this->actingAs($user);

    app(DatabaseManager::class)->connection()->table('categories')->insert([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'slug' => 'groceries-'.$user->id,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(PaletteSearchEndpoint::class);
    $component->call('search', 'Groceries');

    $entityHits = $component->get('entityHits');
    expect($entityHits)->not->toBeEmpty()
        ->and($entityHits[0]['type'])->toBe('category')
        ->and($entityHits[0]['label'])->toBe('Groceries');
});
