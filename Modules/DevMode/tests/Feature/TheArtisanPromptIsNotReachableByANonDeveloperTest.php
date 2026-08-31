<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Http\Livewire\CommandArgPromptModal;

// The modal is mounted by the app layout on every authenticated page, so the
// /dev route gate never sees it. Without its own check, a reader who was
// deliberately left non-developer can spawn an artisan command from any screen
// — including db:backup, whose destination is a free path.

function nonDeveloperReader(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => false,
    ]);
}

it('refuses to open the prompt for a reader who is not a developer', function (): void {
    $user = nonDeveloperReader('prompt-gate-open');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->assertSet('command', '');
});

it('refuses to spawn for a reader who is not a developer', function (): void {
    $user = nonDeveloperReader('prompt-gate-submit');

    // A spawn announces itself with the run id; nothing announced is nothing
    // started. CommandSpawner is final, so the observable is the seam.
    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->set('values', ['config' => 'app'])
        ->call('submit')
        ->assertNotDispatched('toast')
        ->assertSet('submitError', '');
});

it('does not put the prompt on a non-developer page at all', function (): void {
    $user = nonDeveloperReader('prompt-gate-layout');

    $this->actingAs($user)->get('/goals')
        ->assertOk()
        ->assertDontSee('dev.command-arg-prompt-modal');
});
