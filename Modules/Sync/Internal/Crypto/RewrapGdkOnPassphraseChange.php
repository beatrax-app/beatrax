<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Auth\Public\Events\AppLockPassphraseChanged;

/**
 * D-10: re-wraps the GDK keyring whenever the app-lock passphrase changes.
 *
 * Registered in `SyncServiceProvider::boot()` (single-owner, Plan 02
 * forward-registration) against `Modules\Auth\Public\Events\AppLockPassphraseChanged`.
 * This class only ever consumes an Auth Public event — it never reaches
 * into `Modules\Auth\Internal` — so the module boundary is respected in
 * both directions.
 */
final class RewrapGdkOnPassphraseChange
{
    public function __construct(
        private readonly GdkRewrapContract $rewrap,
    ) {}

    public function handle(AppLockPassphraseChanged $event): void
    {
        $this->rewrap->rewrap($event->userId, $event->oldKek, $event->newKek);
    }
}
