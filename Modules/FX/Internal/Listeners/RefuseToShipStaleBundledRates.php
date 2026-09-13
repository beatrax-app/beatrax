<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Modules\Core\Public\Contracts\Clock;
use Modules\FX\Internal\Build\BundledSnapshot;
use Modules\FX\Internal\Build\StaleBundledRatesException;

// The packaging commands and not native:run: a developer iterating locally is
// shown the staleness mark like any reader, while a bundle handed to somebody
// else carries rates they cannot refresh without opting into the network.
final readonly class RefuseToShipStaleBundledRates
{
    public const array SHIPS_THE_BUNDLED_SNAPSHOT = [
        'mobile:package-android',
        'native:build',
        'native:package',
    ];

    public function __construct(
        private BundledSnapshot $snapshot,
        private Clock $clock,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::SHIPS_THE_BUNDLED_SNAPSHOT, true)) {
            return;
        }

        $stale = $this->snapshot->staleness($this->clock->now());

        if ($stale === null) {
            return;
        }

        throw new StaleBundledRatesException($stale->sentence($event->command));
    }
}
