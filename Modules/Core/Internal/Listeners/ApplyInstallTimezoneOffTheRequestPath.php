<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Modules\Core\Public\Services\InstallTimezone;

// `SetInstallTimezone` binds the zone on the `web` group, and a scheduled
// command or a queued job never passes through it. Both then write their
// DATETIME columns in whatever `config('app.timezone')` bootstrapped to, which
// on every shipped bundle is UTC because none of them pins APP_TIMEZONE.

// Measured with the pin removed: a console run reports zone() as
// Europe/Amsterdam and now() as 21:51 while the wall clock says 23:51. So one
// installation wrote its requests in one frame and its background work in
// another, which is the failure InstallTimezone exists to prevent.
final readonly class ApplyInstallTimezoneOffTheRequestPath
{
    // Applying the zone mutates `config('app.timezone')`, and these two
    // serialise the configuration to disk. Letting them observe a resolved
    // value would bake the packager's zone into a cached config — the pin no
    // shipped bundle carries, arriving by the back door.
    private const array SERIALISES_THE_CONFIG = ['config:cache', 'optimize'];

    public function __construct(private InstallTimezone $timezone) {}

    public function handle(CommandStarting|JobProcessing $event): void
    {
        if ($event instanceof CommandStarting && in_array($event->command, self::SERIALISES_THE_CONFIG, true)) {
            return;
        }

        $this->timezone->apply($this->timezone->zone());
    }
}
