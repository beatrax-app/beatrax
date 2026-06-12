<?php

declare(strict_types=1);
use Livewire\Livewire;
use Modules\Search\Internal\Http\Livewire\PaletteSearchEndpoint;

// Wave 0 RED — implemented in Plan 05 (PaletteSearchEndpoint Livewire component)

/**
 * PaletteSearchEndpointTest — SRCH-02 palette endpoint test.
 *
 * RED in Wave 0.  Requires PaletteSearchEndpoint (Plan 05) to be registered
 * as a Livewire component and mounted in the global layout.
 *
 * D-01: The palette returns the top-5 transaction hits in a "transactions"
 * section alongside entity name hits.
 */

// SRCH-02: palette endpoint returns top-5 transaction hits
it('it_returns_top_five_transaction_hits', function (): void {
    // Wave 0 RED — implemented in Plan 05
    $userId = $this->searchTestUser('palette-user-a');

    // Seed 7 transactions matching the query — endpoint must cap at 5
    for ($i = 1; $i <= 7; $i++) {
        $this->searchTestTransaction($userId, [
            'counterparty_name' => "Heijn Supermarkt {$i}",
            'description' => "Weekly shop number {$i}",
        ]);
    }

    // The PaletteSearchEndpoint is a Livewire component registered under
    // 'search.palette-search-endpoint'.  Once Plan 05 lands, the component
    // should be mountable and its search() action callable.
    $component = Livewire::test(
        PaletteSearchEndpoint::class,
    );

    // Act — call the search action with a query that matches all 7 seeds
    $component->call('search', 'Heijn', $userId);

    // Assert — top-5 cap
    $hits = $component->get('transactionHits');
    expect($hits)->toHaveCount(5);
});
