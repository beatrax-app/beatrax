<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire\Concerns;

use Carbon\CarbonImmutable;

// Behaviour, not state: every method reads the component's own iso properties
// rather than declaring any of its own.
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

    // last_attempt_status is 'ok' on success and never null once an attempt
    // has run, so anything else is a failure.
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

        return $dt->diffForHumans().' · '.$dt->translatedFormat('d M Y, H:i');
    }
}
