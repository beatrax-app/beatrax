<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Auth\Public\Events\AppLockPassphraseChanged;

/**
 * Invalidates a cold-start biometric enrollment when the app-lock DATA KEY
 * actually rotates — so a stale enclave blob (which wraps the OLD key) can
 * never recover a key that no longer decrypts anything.
 *
 * Correct hook (verified against the key-wrap chain): the cold-start vault
 * wraps the app-lock data key that `AppLockKeyService::release()` exposes —
 * the KEK that in turn wraps the GDK keyring. A GDK epoch rotation / device
 * revocation (the Sync module's GdkRotationService) re-wraps the new epoch
 * UNDER that same KEK and does NOT change it, so the cold-start blob stays
 * valid across rekeys — clearing on a GDK rotation would be wrong.
 * The KEK only changes if a flow genuinely rotates the app-lock data key,
 * which surfaces as `AppLockPassphraseChanged` with `oldKek !== newKek`.
 *
 * Today a plain PIN change keeps the data key (oldKek === newKek), so this is
 * a no-op; the listener exists so the contract stays correct the moment a
 * future flow DOES rotate the underlying key (as the event docblock foresees).
 *
 * Subscribes to an Auth Public event; never reaches into Auth internals. Only
 * touches the enclave when the user is actually enrolled.
 */
final class ClearColdStartVaultOnKeyRotation
{
    public function __construct(
        private readonly ColdStartEnrollmentService $enrollment,
    ) {}

    public function handle(AppLockPassphraseChanged $event): void
    {
        // Normal PIN change → data key unchanged → the enclave blob still
        // unwraps to the correct key. Nothing to do.
        if (hash_equals($event->oldKek, $event->newKek)) {
            return;
        }

        // The data key actually rotated: the enclave blob wraps the OLD key and
        // is now useless. Clear it so the user re-enrolls under the new key.
        if ($this->enrollment->isEnrolled($event->userId)) {
            $this->enrollment->disable($event->userId);
        }
    }
}
