<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// Every seeded candidate was a hint row, and both the queue and the badge
// filter a NULL to_transaction_id out as unactionable.

it('leaves the demo user a candidate the review queue can act on', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($user);

    $query = app(ChainLinkQuery::class);

    expect($query->candidatesForReview($user))->not->toBeEmpty()
        ->and($query->openCandidateCount($user))->toBeGreaterThanOrEqual(1);
});

it('renders a confirm and a reject control on the seeded review queue', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();

    Livewire::actingAs($user)
        ->test(ChainReviewQueue::class)
        ->assertSee('wire:click="confirm(', false)
        ->assertSee('wire:click="reject(', false);
});
