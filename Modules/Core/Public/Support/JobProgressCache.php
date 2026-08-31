<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Public\Enums\Duration;

// How long a long-running pass's progress payload survives in the cache. It
// outlasts any pass that writes one and expires on its own if the worker dies
// mid-run. The rule-reapply job and the encryption migration each carried
// their own 3600, and neither reached the Duration enum.
final class JobProgressCache
{
    public static function ttlSeconds(): int
    {
        return Duration::Hour->seconds();
    }
}
