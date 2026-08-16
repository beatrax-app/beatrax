<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

// A platform-gated store for the unlocked data key, so a returning user can
// unlock with the OS biometric instead of re-entering the PIN. A prompt alone
// only returns a bool; unlocking needs the KEY back.

// Implementations MUST gate recover() behind the OS authentication prompt and
// MUST persist nothing the OS cannot re-protect.
/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
interface ColdStartVault
{
    // False on platforms with no biometric gate, so callers can hide the
    // affordance without handling exceptions.
    public function isAvailable(): bool;

    // Whether this user has a key stored. MUST NOT prompt — the lock screen
    // reads this on render.
    public function isEnrolled(int $userId): bool;

    // Called while unlocked, with the live data key. False when the platform
    // refused to protect it; the caller keeps PIN-only unlock.
    public function enroll(int $userId, string $dataKey): bool;

    // Prompts, then returns the data key, or null when the user cancelled,
    // the prompt failed, or nothing is enrolled.
    public function recover(int $userId, string $reason): ?string;

    // Invalidates the stored key — a passphrase change makes it undecryptable
    // anyway, and leaving it invites a confusing failed unlock.
    public function forget(int $userId): void;
}
