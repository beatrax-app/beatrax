<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

uses(RefreshDatabase::class);

/*
 * An empty triage queue must not claim to be finished work.
 *
 * With nothing to label, the header read "0 of 0 · 100 % · ~1 min remaining"
 * above a completely filled progress bar — asserting both that there was work
 * and that it was complete, from a queue that never held anything. Zero of
 * zero is not a hundred per cent, and "nothing to do" is a different state
 * from "done", which the all-caught-up card below already states correctly.
 */

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

    // And the state that IS true is still on screen.
    $rendered->assertSee(__('counterparties::triage.all_caught_heading'));
});

it('reports zero per cent rather than one hundred for an empty queue', function (): void {
    $user = User::query()->create([
        'username' => 'triage-empty-2',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
    ]);

    // Guards every other reader of the view data, not just the bar's width.
    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->assertViewHas('percent', 0)
        ->assertViewHas('total', 0)
        ->assertViewHas('minutesRemaining', 0);
});
