<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Events;

/**
 * Dispatched by `Modules\Auth\Internal\Lock\AppLockProvisioner::changePin()`
 * immediately after a successful app-lock passphrase (PIN) change, carrying
 * the OLD and NEW app-lock data keys so a Sync listener can re-wrap the GDK
 * (Group Data Key) keyring under the new key (D-10) before this call
 * returns success.
 *
 * In THIS codebase's key-wrap chain the app-lock "data key" itself never
 * rotates on a plain `changePin()` call — only the PIN-derived WRAP of that
 * (persistent) data key changes. `$oldKek`/`$newKek` are therefore the same
 * raw bytes for a normal PIN change; the event/listener plumbing still
 * fires unconditionally so (a) the GDK keyring file is re-encrypted with a
 * fresh nonce every time the passphrase changes, and (b) the contract stays
 * correct if a future flow ever DOES rotate the underlying data key.
 *
 * SECURITY: `$oldKek`/`$newKek` are raw, TRANSIENT key-encryption-key
 * material — the exact same "data key" `AppLockKeyWrap` wraps/unwraps and
 * `AppLockKeyService::release()` exposes. They must NEVER be logged,
 * serialized to disk/session, or persisted anywhere beyond this
 * synchronous, in-process event dispatch. Listeners must `sodium_memzero()`
 * their own copies once the re-wrap completes (or immediately, on a no-op
 * path).
 *
 * Auth has ZERO compile-time dependency on Sync — this event is Auth-owned;
 * Sync subscribes to it from its own listener (module-boundary rule,
 * mirrors the `Modules\Import\Public\Events\TransactionImported` precedent).
 */
final readonly class AppLockPassphraseChanged
{
    public function __construct(
        public int $userId,
        public string $oldKek,
        public string $newKek,
    ) {}
}
