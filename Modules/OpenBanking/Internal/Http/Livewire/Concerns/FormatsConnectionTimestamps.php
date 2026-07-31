<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire\Concerns;

use Carbon\CarbonImmutable;

// The read-side presentation of the connection's freshness signals: turning
// the ISO timestamps the settings page holds into the relative + absolute
// strings the transparency panel and consent banner render. Behaviour, not
// state — every method reads the component's own iso properties.
/**
 * @link ../../../../../../.docs/features/open-banking/architecture.md
 */
trait FormatsConnectionTimestamps
{
    public function lastSuccessfulSyncRelative(): ?string
    {
        if ($this->lastSuccessfulSyncAtIso === null) {
            return null;
        }

        return CarbonImmutable::parse($this->lastSuccessfulSyncAtIso)->diffForHumans();
    }

    public function lastSuccessfulSyncDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastSuccessfulSyncAtIso);
    }

    public function lastAttemptDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastAttemptAtIso);
    }

    // Renders only when the last attempt did not succeed —
    // last_attempt_status is 'ok' on success, 'consent_failed'/'error' on
    // failure, never null once at least one attempt has run.
    public function lastAttemptFailed(): bool
    {
        return $this->lastAttemptStatus !== null && $this->lastAttemptStatus !== 'ok';
    }

    private static function relativeAndAbsolute(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }

        $dt = CarbonImmutable::parse($iso);

        return $dt->diffForHumans().' · '.$dt->format('d M Y, H:i');
    }
}
