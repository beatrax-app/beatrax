<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Listeners\ApplyUpdateCheckChoiceToStartupConfig;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// `native:config` is a separate artisan process the Electron main process runs
// at bootstrap; its JSON reply is the only thing the main process knows before
// it decides whether electron-updater polls the feed. Nothing downstream of
// that decision can hold the call back, so the reader's answer has to arrive
// here or the switch stops only the PHP half.

function startupConfigReader(bool $checkEnabled): User
{
    return User::create([
        'username' => 'startup-'.($checkEnabled ? 'on' : 'off'),
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'auto_update_check_enabled' => $checkEnabled,
    ]);
}

function startupConfigStarting(string $command): CommandStarting
{
    return new CommandStarting($command, new ArrayInput([]), new BufferedOutput);
}

it('turns the Electron boot poll off when the startup config is read', function (): void {
    startupConfigReader(false);
    config()->set('nativephp.updater.enabled', true);

    app(ApplyUpdateCheckChoiceToStartupConfig::class)->handle(
        startupConfigStarting(ApplyUpdateCheckChoiceToStartupConfig::STARTUP_CONFIG_COMMAND),
    );

    expect(config('nativephp.updater.enabled'))->toBeFalse();
});

it('leaves the Electron boot poll alone for every other command', function (): void {
    startupConfigReader(false);
    config()->set('nativephp.updater.enabled', true);

    app(ApplyUpdateCheckChoiceToStartupConfig::class)->handle(startupConfigStarting('migrate'));

    expect(config('nativephp.updater.enabled'))->toBeTrue();
});

it('never widens a build that ships without an update feed', function (): void {
    startupConfigReader(true);
    config()->set('nativephp.updater.enabled', false);

    app(ApplyUpdateCheckChoiceToStartupConfig::class)->handle(
        startupConfigStarting(ApplyUpdateCheckChoiceToStartupConfig::STARTUP_CONFIG_COMMAND),
    );

    expect(config('nativephp.updater.enabled'))->toBeFalse();
});
