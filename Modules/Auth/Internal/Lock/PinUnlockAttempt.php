<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// The answer an unlock attempt reaches before the write transaction that acts
// on it opens, so seconds of Argon2id are not held across an open write. A
// corrupt blob and a wrong PIN both fail, and only the PIN verifier separates
// them, so which one it was travels here rather than being decided under lock.
final class PinUnlockAttempt
{
    private function __construct(
        public readonly ?string $dataKey,
        public readonly ?string $corruptionDetail,
        public readonly bool $pinChangedMidAttempt = false,
    ) {}

    public static function unlocked(string $dataKey): self
    {
        return new self($dataKey, null);
    }

    public static function corrupted(string $detail): self
    {
        return new self(null, $detail);
    }

    public static function wrongPin(): self
    {
        return new self(null, null);
    }

    // Reached only under the write lock, and the one answer that is not a
    // judgement: the row this attempt described is gone, so nothing was
    // recorded. A caller that reads it as a refusal names a remaining count
    // the reader can watch stand still.
    public static function outracedByAPinChange(): self
    {
        return new self(null, null, true);
    }
}
