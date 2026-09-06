<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Modules\Core\Public\Services\UpdateChannelPreference;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md#the-two-channels
 */
final readonly class ApplyUpdateChannelChoiceToStartupConfig
{
    // electron-updater's own poll runs in the Electron main process, out of the
    // JSON `native:config` prints, and it resolves a manifest by channel name.
    // Beatrax's verification fetch reads the same answer from the same column,
    // so without this the two halves of one update would ask for two files.
    public const string CHANNEL_KEY = 'nativephp.updater.providers.github.channel';

    public function __construct(
        private Repository $config,
        private UpdateChannelPreference $channels,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if ($event->command !== ApplyUpdateCheckChoiceToStartupConfig::STARTUP_CONFIG_COMMAND) {
            return;
        }

        // Only the provider whose channel names a manifest this pipeline
        // publishes. The S3 and Spaces providers resolve a path instead, so
        // writing a channel there would name a key their driver never reads.
        if ($this->config->get('nativephp.updater.default') !== 'github') {
            return;
        }

        $this->config->set(self::CHANNEL_KEY, $this->channels->channel()->manifestPrefix());
    }
}
