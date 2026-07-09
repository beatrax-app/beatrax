<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Core\Public\Services\UserDataPathService;

/**
 * Delegates D-10 passphrase-change re-wraps to `GdkKeyringService::rewrapUnderNewKek()`.
 *
 * Guards the "clean no-op when no keyring exists" requirement itself
 * (rather than relying on `GdkKeyringService`): `rewrapUnderNewKek()`'s
 * `readKeyringFile()` silently returns an EMPTY keyring when no file exists
 * yet, and `writeKeyringFile()` would then happily encrypt+persist that
 * empty keyring under `$newKek` — fabricating a keyring file for a user who
 * never enabled encryption. Checking file existence first (mirroring
 * `GdkKeyringService`'s own private `keyringPath()` computation, safe to
 * duplicate here since both classes live in the same `Sync\Internal\Crypto`
 * namespace) keeps this a genuine no-op.
 */
final class GdkRewrapService implements GdkRewrapContract
{
    public function __construct(
        private readonly GdkKeyringService $keyringService,
    ) {}

    public function rewrap(int $userId, string $oldKek, string $newKek): void
    {
        if (! file_exists($this->keyringPath($userId))) {
            // No keyring yet for this user (encryption not enabled) — clean
            // no-op; never delegate to rewrapUnderNewKek() here, it would
            // otherwise write a fresh empty keyring file where none existed.
            sodium_memzero($oldKek);
            sodium_memzero($newKek);

            return;
        }

        $this->keyringService->rewrapUnderNewKek($userId, $oldKek, $newKek);
    }

    private function keyringPath(int $userId): string
    {
        return UserDataPathService::appPath("sync/gdk/{$userId}.enc");
    }
}
