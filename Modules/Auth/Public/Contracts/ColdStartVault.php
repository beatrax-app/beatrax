<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

// Stores the data key rather than just prompting, because a prompt returns a
// bool and unlocking needs the key itself.

// Implementations MUST gate recover() behind the OS authentication prompt and
// MUST persist nothing the OS cannot re-protect.
interface ColdStartVault
{
    // False on platforms with no biometric gate, so callers can hide the
    // affordance without handling exceptions.
    public function isAvailable(): bool;

    // MUST NOT prompt: the lock screen reads this on render.
    public function isEnrolled(int $userId): bool;

    // Called while unlocked. False when the platform refused to protect the
    // key, leaving the caller on PIN-only unlock.
    public function enroll(int $userId, string $dataKey): bool;

    // Prompts, then returns the data key, or null when the user cancelled,
    // the prompt failed, or nothing is enrolled.
    public function recover(int $userId, string $reason): ?string;

    // A passphrase change leaves the stored key undecryptable, so it goes
    // rather than failing an unlock confusingly later.
    public function forget(int $userId): void;
}
