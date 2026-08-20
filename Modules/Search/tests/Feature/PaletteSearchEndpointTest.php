<?php

declare(strict_types=1);
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Search\Internal\Http\Livewire\PaletteSearchEndpoint;

it('it_returns_top_five_transaction_hits', function (): void {
    $user = User::find($this->searchTestUser('palette-user-a'));
    $this->actingAs($user);

    // Seven matches, so the five-hit cap is observable rather than incidental.
    for ($i = 1; $i <= 7; $i++) {
        $this->searchTestTransaction($user->id, [
            'counterparty_name' => "Heijn Supermarkt {$i}",
            'description' => "Weekly shop number {$i}",
        ]);
    }

    $component = Livewire::test(PaletteSearchEndpoint::class);

    $component->call('search', 'Heijn');

    $hits = $component->get('transactionHits');
    expect($hits)->toHaveCount(5);
});

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
