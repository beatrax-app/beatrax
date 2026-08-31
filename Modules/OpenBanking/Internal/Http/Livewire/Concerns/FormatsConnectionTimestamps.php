<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;

// Behaviour, not state: every method reads the component's own iso properties
// rather than declaring any of its own. Each one is a string the panel shows
// only when it can be read, because a bare parse throws on anything else and
// the caller has no answer for that but a stack trace.
trait FormatsConnectionTimestamps
{
    public function lastSuccessfulSyncRelative(): ?string
    {
        return SafeDate::parseOrNull($this->lastSuccessfulSyncAtIso ?? '')?->diffForHumans();
    }

    public function lastSuccessfulSyncDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastSuccessfulSyncAtIso);
    }

    public function lastAttemptDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastAttemptAtIso);
    }

    public function lastAttemptFailed(): bool
    {
        return SyncAttemptStatus::failedIn($this->lastAttemptStatus);
    }

    private static function relativeAndAbsolute(?string $iso): ?string
    {
        $dt = SafeDate::parseOrNull($iso ?? '');

        if (! $dt instanceof CarbonImmutable) {
            return null;
        }

        return $dt->diffForHumans().' · '.$dt->translatedFormat('d M Y, H:i');
    }
}
