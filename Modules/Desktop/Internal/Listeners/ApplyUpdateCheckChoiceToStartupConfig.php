<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Modules\Core\Public\Services\UpdateCheckPreference;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md#the-off-switch
 */
final readonly class ApplyUpdateCheckChoiceToStartupConfig
{
    // The command Electron runs at bootstrap to read `config('nativephp')` as
    // JSON. It is the only moment the main process asks PHP anything before it
    // decides whether to poll the feed, so it is the only moment the reader's
    // stored answer can reach that decision.
    public const string STARTUP_CONFIG_COMMAND = 'native:config';

    public function __construct(
        private Repository $config,
        private UpdateCheckPreference $preference,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if ($event->command !== self::STARTUP_CONFIG_COMMAND) {
            return;
        }

        // Narrowed, never widened: a bundle built without a feed stays without
        // one whatever the row says, so this can only ever turn the check off.
        if ($this->config->get('nativephp.updater.enabled') !== true) {
            return;
        }

        $this->config->set('nativephp.updater.enabled', $this->preference->enabled());
    }
}
