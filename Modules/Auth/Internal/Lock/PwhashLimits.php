<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// The Argon2id cost the PIN hash and the wrap-key derivation both run at.
// MODERATE (~256 MB, ~500ms) is the shipped tier, and the one a stolen
// database is defended at; the reduced tier exists for the test suite, which
// otherwise spends most of its wall clock inside sodium.
final readonly class PwhashLimits
{
    public const string PRODUCTION_TIER = 'moderate';

    public const string REDUCED_TIER = 'interactive';

    private function __construct(
        public int $opslimit,
        public int $memlimit,
    ) {}

    // Only the literal reduced tier lowers the cost. An unset, empty or
    // misspelled value resolves to MODERATE, so no environment can weaken the
    // derivation by accident -- it has to ask for the weaker tier by name.
    public static function fromTier(mixed $tier): self
    {
        return $tier === self::REDUCED_TIER
            ? new self(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE)
            : new self(SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE);
    }
}
