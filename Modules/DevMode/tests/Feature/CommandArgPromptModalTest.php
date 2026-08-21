<?php

declare(strict_types=1);

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Http\Livewire\CommandArgPromptModal;

function promptUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

it('open() seeds the values map from the CommandSpec\'s argsSchema and dispatches modal-show', function (): void {
    $user = promptUser('arg-prompt-open');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->assertSet('command', 'config:show')
        ->assertSet('values.config', '')
        ->assertDispatched(
            'modal-show',
            fn (string $event, array $params) => ($params['name'] ?? null) === 'command-args',
        );
});

it('submit() spawns through CommandSpawner when every required arg is filled', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = promptUser('arg-prompt-submit-ok');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->set('values.config', 'app')
        ->call('submit')
        ->assertNotDispatched('spawn-command')
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => is_string($params['message'] ?? null)
                && str_starts_with($params['message'], 'Started config:show'),
        )
        ->assertDispatched(
            'modal-close',
            fn (string $event, array $params) => ($params['name'] ?? null) === 'command-args',
        );
});

it('submit() with a missing required arg refuses to spawn and surfaces a clear submitError', function (): void {
    $user = promptUser('arg-prompt-missing');

    $component = Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->set('values.config', '')
        ->call('submit')
        ->assertNotDispatched('spawn-command')
        ->assertNotDispatched('toast');

    expect($component->get('submitError'))->toContain('Missing');
    expect($component->get('submitError'))->toContain('Config key');
});

it('submit() with a DESTRUCTIVE-tier name routes to triple-gate:open (defence-in-depth against hostile dispatch)', function (): void {
    $user = promptUser('arg-prompt-destructive');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'db:restore', tier: 'safe', prefill: ['from' => '/tmp/x'])
        ->call('submit')
        ->assertNotDispatched('spawn-command')
        ->assertNotDispatched('toast')
        ->assertDispatched(
            'triple-gate:open',
            fn (string $event, array $params) => ($params['command'] ?? null) === 'db:restore',
        );
});

it('submit() drops empty optional values from the args map so the shell never sees a blank arg', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    // db:backup's `destination` is optional, and a blank kept in the map
    // would build `php artisan db:backup ''`, which Laravel can reject.
    $user = promptUser('arg-prompt-optional');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'db:backup', tier: 'safe', prefill: [])
        ->set('values.destination', '')
        ->call('submit')
        ->assertNotDispatched('spawn-command')
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => is_string($params['message'] ?? null)
                && str_starts_with($params['message'], 'Started db:backup'),
        );
});

it('submit() works from any page — does not depend on a #[On(spawn-command)] listener being mounted', function (): void {
    // Regression: submit() used to dispatch `spawn-command` and lean on
    // ArtisanRunnerPage's listener, so opening the modal from /dev/logs
    // dropped the spawn silently. Mounting the modal alone is the proof.
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = promptUser('arg-prompt-off-page');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->set('values.config', 'app')
        ->call('submit')
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => is_string($params['message'] ?? null)
                && str_starts_with($params['message'], 'Started config:show'),
        );
});

it('submit() rejects an arg value that violates its ArgSpec rules instead of spawning', function (): void {
    // beatrax:failed-jobs declares `action` as ['required', 'in:prune'].
    // $values is client-supplied, so before the rules ran on this path a
    // crafted payload put an arbitrary token where the enum was promised.
    // escapeshellarg still held, but the enum guard held nowhere.
    $user = promptUser('arg-prompt-rule-violation');

    $component = Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'beatrax:failed-jobs', tier: 'safe', prefill: [])
        ->set('values.action', 'not-prune')
        ->call('submit')
        ->assertNotDispatched('spawn-command')
        ->assertNotDispatched('toast');

    expect($component->get('submitError'))->not->toBe('');
});

it('submit() still spawns when the arg value satisfies its ArgSpec rules', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = promptUser('arg-prompt-rule-ok');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'beatrax:failed-jobs', tier: 'safe', prefill: [])
        ->set('values.action', 'prune')
        ->call('submit')
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => is_string($params['message'] ?? null)
                && str_starts_with($params['message'], 'Started beatrax:failed-jobs'),
        );
});

it('refuses a client-side write to the #[Locked] $command property', function (): void {
    // Without the lock a client could let open() populate the form for a
    // SAFE entry, then swap $command before submit() resolves the spec.
    $user = promptUser('arg-prompt-locked-command');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->set('command', 'db:restore');
})->throws(CannotUpdateLockedPropertyException::class);
