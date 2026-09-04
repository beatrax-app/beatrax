<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Carbon\CarbonImmutable;

// The one rule for how long a ceremony still has to run, and the one place the
// five minutes is written. Three moments move it: the responder binding, an
// unlock the reader came back through, and the local human's tap. A rule each
// of them spelled for itself expired a ceremony both screens were still in.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-pairing-outlives-the-lock-that-interrupts-it
 */
final readonly class CeremonyWindow
{
    public const int GRACE_MINUTES = 5;

    // Grows the window and never shortens it: an extension arriving while a
    // longer one is already running must leave the longer one in place, so the
    // order the three moments happen in cannot cost a ceremony its remaining time.
    public function extendedFrom(mixed $existingExpiresAt, CarbonImmutable $now): CarbonImmutable
    {
        $grace = $now->addMinutes(self::GRACE_MINUTES);

        $existing = is_string($existingExpiresAt) && $existingExpiresAt !== ''
            ? CarbonImmutable::parse($existingExpiresAt)
            : $grace;

        return $grace->greaterThan($existing) ? $grace : $existing;
    }
}
