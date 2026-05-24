<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Config\Repository;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Http\Livewire\TripleGateModal;

/*
 * TripleGateModal invariants.
 *
 * Server-side enforcement of three locks:
 *   Gate 1 — DevModeFlag->isOn() (config('app.dev_mode') === true)
 *   Gate 2 — session('dev_mode.advanced') === true
 *   Gate 3 — hash_equals('beatrax', typed)
 *
 * Each gate has its own rejection path; all three pass dispatches
 * `triple-gate:confirmed` so the runner-page listener can POST to
 * DestructiveSpawnController (which re-validates the gates a
 * second time as defense-in-depth).
 */

function tripleGateUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function setDevModeFlag(bool $on): void
{
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('app.dev_mode', $on);
}

it('rejects with dev_mode_off when DevModeFlag->isOn() returns false', function (): void {
    $user = tripleGateUser('tg-dev-mode-off');
    setDevModeFlag(false);
    $this->actingAs($user);

    Livewire::test(TripleGateModal::class)
        ->dispatch('triple-gate:open', command: 'db:restore', args: ['from' => '/tmp/x'])
        ->set('typed', 'beatrax')
        ->call('confirm')
        ->assertHasErrors(['_gate'])
        ->assertNotDispatched('triple-gate:confirmed');
});

it('rejects with advanced_off when session.dev_mode.advanced is not true', function (): void {
    $user = tripleGateUser('tg-advanced-off');
    setDevModeFlag(true);
    $this->actingAs($user);

    Livewire::test(TripleGateModal::class)
        ->dispatch('triple-gate:open', command: 'db:restore', args: [])
        ->set('typed', 'beatrax')
        ->call('confirm')
        ->assertHasErrors(['_gate'])
        ->assertNotDispatched('triple-gate:confirmed');
});

it('rejects with app_name_mismatch when typed is "Beatrax" (capital B — case-sensitive)', function (): void {
    $user = tripleGateUser('tg-case');
    setDevModeFlag(true);
    $this->actingAs($user);
    session()->put('dev_mode.advanced', true);

    Livewire::test(TripleGateModal::class)
        ->dispatch('triple-gate:open', command: 'db:restore', args: [])
        ->set('typed', 'Beatrax')
        ->call('confirm')
        ->assertHasErrors(['typed'])
        ->assertNotDispatched('triple-gate:confirmed');
});

it('dispatches triple-gate:confirmed when all three gates pass', function (): void {
    $user = tripleGateUser('tg-happy');
    setDevModeFlag(true);
    $this->actingAs($user);
    session()->put('dev_mode.advanced', true);

    Livewire::test(TripleGateModal::class)
        ->dispatch('triple-gate:open', command: 'db:restore', args: ['from' => '/tmp/backup.sqlite'])
        ->set('typed', 'beatrax')
        ->call('confirm')
        ->assertDispatched('triple-gate:confirmed')
        ->assertDispatched('modal-hide');
});

it('clears typed state after a successful confirm', function (): void {
    $user = tripleGateUser('tg-clear');
    setDevModeFlag(true);
    $this->actingAs($user);
    session()->put('dev_mode.advanced', true);

    $component = Livewire::test(TripleGateModal::class)
        ->dispatch('triple-gate:open', command: 'cache:clear', args: [])
        ->set('typed', 'beatrax')
        ->call('confirm');

    expect($component->get('typed'))->toBe('');
});

it('Login event clears session.dev_mode.advanced via ResetAdvancedToggleOnLogin listener', function (): void {
    $user = tripleGateUser('tg-login-reset');

    session()->put('dev_mode.advanced', true);
    expect(session('dev_mode.advanced'))->toBeTrue();

    event(new Login('web', $user, false));

    expect(session()->has('dev_mode.advanced'))->toBeFalse();
});
