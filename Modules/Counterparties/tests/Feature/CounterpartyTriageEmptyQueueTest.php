<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

uses(RefreshDatabase::class);

// Regression: with nothing to label the header read "0 of 0 · 100 % · ~1 min
// remaining" over a full progress bar. "Nothing to do" is not "done".

it('shows no progress figure or bar when nothing is queued', function (): void {
    $user = User::query()->create([
        'username' => 'triage-empty',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
    ]);

    $rendered = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    // The bar itself is gone, not merely emptied: a 0%-wide bar still
    // advertises a task that does not exist.
    $rendered->assertDontSee('progress-fill', false);
    $rendered->assertDontSee('100 %');

    $rendered->assertSee(__('counterparties::triage.all_caught_heading'));
});

it('reports zero per cent rather than one hundred for an empty queue', function (): void {
    $user = User::query()->create([
        'username' => 'triage-empty-2',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
    ]);

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->assertViewHas('percent', 0)
        ->assertViewHas('total', 0)
        ->assertViewHas('minutesRemaining', 0);
});
