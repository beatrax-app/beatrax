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

    // Must not prompt: the lock screen calls this on every render, so a prompt
    // here would fire before the user has asked to unlock anything.
    public function isEnrolled(int $userId): bool;

    // Called while unlocked. False when the platform refused to protect the
    // key, leaving the caller on PIN-only unlock.
    public function enroll(int $userId, string $dataKey): bool;

    // Prompts, then returns the data key, or null when the user cancelled,
    // the prompt failed, or nothing is enrolled.

    // Implementations MUST drop an entry they could not read before answering,
    // so that isEnrolled() afterwards separates a prompt the reader declined
    // from an enrolment the platform destroyed. Nothing else can tell them
    // apart: only the read learns that a stored wrap will never open again.
    public function recover(int $userId, string $reason): ?string;

    // A passphrase change leaves the stored key undecryptable, so it goes
    // rather than failing an unlock confusingly later.

    // True when no durable wrap of this user's data key is left. False when the
    // platform refused to release one, which leaves a key the reader asked to
    // be rid of recoverable by whoever can pass the OS prompt.

    // Implementations MUST answer from what the store holds afterwards rather
    // than from the call having been made, and MUST NOT report true on a
    // refusal.
    public function forget(int $userId): bool;
}
