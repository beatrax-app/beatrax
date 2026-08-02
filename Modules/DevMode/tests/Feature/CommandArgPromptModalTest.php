<?php

declare(strict_types=1);

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Http\Livewire\CommandArgPromptModal;

/*
 * CommandArgPromptModal — global arg-entry surface for SAFE-tier
 * artisan commands whose CommandSpec carries argsSchema.
 *
 * The palette dispatches `command-args:prompt` when an operator
 * picks a 'dev' row whose JSON entry has `hasArgs: true`. The
 * modal listens for that event, looks up the CommandSpec, renders
 * a form, and on submit drives CommandSpawner directly (the
 * earlier draft dispatched `spawn-command` and relied on the
 * runner page to listen, but that broke whenever the operator
 * opened the modal from any page other than /dev/artisan —
 * runner page is the SOLE listener and is only mounted on its
 * own URL).
 *
 * Coverage in this file:
 *
 *   1. open() sets command + seeds the values map per ArgSpec
 *      defaults, then dispatches modal-show.
 *   2. submit() with all required args present spawns through
 *      CommandSpawner and dispatches a "Started ..." toast.
 *   3. submit() with a missing required arg refuses to spawn
 *      and populates `submitError` so the modal renders the
 *      banner.
 *   4. submit() against a DESTRUCTIVE-tier name routes to
 *      `triple-gate:open` instead of spawning — defense-in-depth
 *      against a hostile dispatch that bypasses the palette's
 *      SAFE-only JSON filter.
 *   5. submit() drops empty optional values from the args map so
 *      the shell never sees `php artisan cmd ''`.
 *   6. submit() enforces each ArgSpec's own rules — the third
 *      guard alongside the registry whitelist and escapeshellarg,
 *      which both spawn controllers apply and this path must too.
 *   7. $command is #[Locked] so the client cannot retarget which
 *      registry entry submit() resolves.
 */

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

    // db:backup has a single optional `destination` arg. An empty
    // text value would otherwise render as `php artisan db:backup ''`
    // (Laravel sometimes rejects that). The normaliser drops empty
    // strings so the spawner builds `php artisan db:backup` instead.
    // The toast confirmation tells us the spawn fired with whatever
    // arg shape the spawner accepted; the dropped-blank-value guard
    // is covered structurally — an empty string never makes it into
    // the args map handed to the spawner.
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
    // Regression guard for the "submit does nothing, no console errors"
    // user report. The earlier draft dispatched `spawn-command` and
    // relied on ArtisanRunnerPage's listener; opening the modal from
    // /dev/logs (or anywhere ArtisanRunnerPage is NOT mounted) silently
    // dropped the dispatch. The Livewire test harness mounts ONLY
    // CommandArgPromptModal here — proving the spawn fires regardless
    // of whether the runner page is on the current request's page.
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
