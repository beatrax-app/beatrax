<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Desktop\Internal\Listeners\ApplyUpdateChannelChoiceToStartupConfig;
use Modules\Desktop\Internal\Listeners\ApplyUpdateCheckChoiceToStartupConfig;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// One update has two halves that each resolve a manifest: electron-updater's
// own poll, in the Electron main process, and Beatrax's verification fetch in
// PHP. The verification listener refuses an update whose signed manifest names
// a different version than the one that was offered, so two halves reading two
// channels do not produce a wrong install — they produce an update that never
// arrives and a log line about a disagreement nobody caused.

function channelStartupReader(?string $channel): User
{
    $columns = [
        'username' => 'channel-startup',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ];

    return User::create($channel === null ? $columns : $columns + ['update_channel' => $channel]);
}

function channelStartupStarting(string $command): CommandStarting
{
    return new CommandStarting($command, new ArrayInput([]), new BufferedOutput);
}

function channelStartupApply(string $command = ApplyUpdateCheckChoiceToStartupConfig::STARTUP_CONFIG_COMMAND): void
{
    app(ApplyUpdateChannelChoiceToStartupConfig::class)->handle(channelStartupStarting($command));
}

beforeEach(function (): void {
    config()->set('nativephp.updater.default', 'github');
    config()->set(ApplyUpdateChannelChoiceToStartupConfig::CHANNEL_KEY, 'latest');
});

it('leaves the boot poll on the stable manifest set while nobody has chosen otherwise', function (): void {
    channelStartupReader(null);

    channelStartupApply();

    expect(config(ApplyUpdateChannelChoiceToStartupConfig::CHANNEL_KEY))
        ->toBe(UpdateChannel::Stable->manifestPrefix());
});

it('points the boot poll at the preview manifest set once the reader chooses it', function (): void {
    channelStartupReader(UpdateChannel::Preview->value);

    channelStartupApply();

    expect(config(ApplyUpdateChannelChoiceToStartupConfig::CHANNEL_KEY))
        ->toBe(UpdateChannel::Preview->manifestPrefix());
});

it('leaves the startup config alone for every other command', function (): void {
    channelStartupReader(UpdateChannel::Preview->value);

    channelStartupApply('migrate');

    expect(config(ApplyUpdateChannelChoiceToStartupConfig::CHANNEL_KEY))->toBe('latest');
});

// The channel key belongs to the GitHub provider. S3 and Spaces resolve a path
// instead, so a channel written there would be a key their driver never reads
// and a reader's choice that looks applied while changing nothing.
it('writes no channel for a provider whose driver has no channel', function (): void {
    channelStartupReader(UpdateChannel::Preview->value);
    config()->set('nativephp.updater.default', 'spaces');

    channelStartupApply();

    expect(config(ApplyUpdateChannelChoiceToStartupConfig::CHANNEL_KEY))->toBe('latest');
});
