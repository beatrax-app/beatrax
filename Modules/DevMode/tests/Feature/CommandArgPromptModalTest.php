<?php

declare(strict_types=1);

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
 * a form, and on submit dispatches `spawn-command` so the runner
 * page's onSpawnCommand listener fires the actual spawn.
 *
 * Coverage in this file:
 *
 *   1. open() sets command + seeds the values map per ArgSpec
 *      defaults, then dispatches modal-show.
 *   2. submit() with all required args present dispatches
 *      `spawn-command` carrying the assembled args map.
 *   3. submit() with a missing required arg refuses to dispatch
 *      and populates `submitError` so the modal renders the
 *      banner.
 *   4. submit() against a DESTRUCTIVE-tier name routes to
 *      `triple-gate:open` instead of `spawn-command` — defense-
 *      in-depth against a hostile dispatch that bypasses the
 *      palette's SAFE-only JSON filter.
 *   5. submit() drops empty optional values from the args map so
 *      the shell never sees `php artisan cmd ''`.
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

it('submit() dispatches spawn-command with the assembled args when every required arg is filled', function (): void {
    $user = promptUser('arg-prompt-submit-ok');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->set('values.config', 'app')
        ->call('submit')
        ->assertDispatched(
            'spawn-command',
            fn (string $event, array $params) => ($params['name'] ?? null) === 'config:show'
                && ($params['tier'] ?? null) === 'safe'
                && ($params['args']['config'] ?? null) === 'app',
        );
});

it('submit() with a missing required arg refuses to dispatch spawn-command and surfaces a clear submitError', function (): void {
    $user = promptUser('arg-prompt-missing');

    $component = Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->set('values.config', '')
        ->call('submit')
        ->assertNotDispatched('spawn-command');

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
        ->assertDispatched(
            'triple-gate:open',
            fn (string $event, array $params) => ($params['command'] ?? null) === 'db:restore',
        );
});

it('submit() drops empty optional values from the args map so the shell never sees a blank arg', function (): void {
    $user = promptUser('arg-prompt-optional');

    // db:backup has a single optional `destination` arg. An empty
    // text value would otherwise render as `php artisan db:backup ''`
    // (Laravel sometimes rejects that). The normaliser drops empty
    // strings so the spawner builds `php artisan db:backup` instead.
    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'db:backup', tier: 'safe', prefill: [])
        ->set('values.destination', '')
        ->call('submit')
        ->assertDispatched(
            'spawn-command',
            fn (string $event, array $params) => ($params['name'] ?? null) === 'db:backup'
                && ($params['args'] ?? null) === [],
        );
});
