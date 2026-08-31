<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Http\Livewire\CommandArgPromptModal;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;

// Both components are mounted by the layout on every authenticated page, so
// the /dev route gate never sees them: each answers for itself, and each has
// to answer for the build as well as for the account.

function shippedBuildDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function shippedBuild(): void
{
    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('app.env', 'production');
    $config->set('app.dev_mode', false);
    $config->set('app.debug', false);
}

it('keeps every console address out of the palette registry on a shipped build', function (): void {
    $user = shippedBuildDeveloper('shipped-palette');
    shippedBuild();

    $html = Livewire::actingAs($user)->test(CommandPaletteModal::class)->html();

    expect($html)->not->toContain('dev.logs')
        ->and($html)->not->toContain('dev.overview')
        ->and($html)->not->toContain('dev.sql');
});

it('still lists the console in the palette on a development build', function (): void {
    $user = shippedBuildDeveloper('development-palette');

    $html = Livewire::actingAs($user)->test(CommandPaletteModal::class)->html();

    expect($html)->toContain('dev.logs');
});

it('refuses to open the argument prompt on a shipped build', function (): void {
    $user = shippedBuildDeveloper('shipped-arg-prompt');
    shippedBuild();

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->assertSet('command', '')
        ->assertNotDispatched('modal-show');
});

it('still opens the argument prompt on a development build', function (): void {
    $user = shippedBuildDeveloper('development-arg-prompt');

    Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'config:show', tier: 'safe', prefill: [])
        ->assertSet('command', 'config:show');
});
