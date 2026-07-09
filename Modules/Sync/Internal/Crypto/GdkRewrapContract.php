<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

/**
 * Public re-wrap entrypoint for D-10 (passphrase-change GDK re-wrap).
 *
 * NAMESPACE NOTE: 14-07-PLAN.md's frontmatter names this file
 * `Modules/Sync/Public/Contracts/GdkRewrapContract.php`, but Plan 02 already
 * forward-registered the concrete binding in `SyncServiceProvider`
 * (single-owner — not editable by this plan) against the literal runtime
 * string `Modules\Sync\Internal\Crypto\GdkRewrapContract`. This interface
 * lives here so that binding actually resolves. `Modules\Auth` never
 * imports this contract directly — it only ever dispatches its own
 * `AppLockPassphraseChanged` Public event — so the "Auth must not reach
 * into Modules\Sync\Internal" boundary rule is unaffected either way; only
 * `RewrapGdkOnPassphraseChange` (this same Sync\Internal\Crypto namespace)
 * and `SyncServiceProvider`'s own DI binding ever reference it.
 */
interface GdkRewrapContract
{
    /**
     * Re-wrap the user's entire GDK keyring (every epoch) under $newKek.
     * A clean no-op when the user has no keyring file yet (encryption not
     * enabled for this user) — never fabricates a keyring where none
     * existed. $oldKek/$newKek are raw wrap-key bytes; implementations must
     * `sodium_memzero()` both before returning.
     */
    public function rewrap(int $userId, string $oldKek, string $newKek): void;
}
