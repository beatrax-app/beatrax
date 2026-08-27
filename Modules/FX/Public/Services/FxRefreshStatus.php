<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Modules\FX\Public\Dto\FxRefreshFailure;
use Modules\FX\Public\Enums\FxRefreshFailureReason;

// The last refresh attempt that came back with nothing, so a screen waiting on
// one can say what happened instead of timing out and guessing. It lives in the
// cache beside the provider circuit breaker rather than in a table: which rates
// a device could reach is that device's own state and must not travel on the op log.
/**
 * @link ../../../../.docs/features/fx/architecture.md
 */
final class FxRefreshStatus
{
    private const string KEY_PREFIX = 'fx.refresh.';

    private const string KEY_SUFFIX = '.last_failure';

    private const int RETENTION_DAYS = 30;

    public function __construct(private readonly Repository $cache) {}

    public function recordFailure(int $userId, FxRefreshFailureReason $reason): void
    {
        $this->cache->put(
            $this->key($userId),
            ['reason' => $reason->value, 'at' => CarbonImmutable::now()->toDateTimeString()],
            CarbonImmutable::now()->addDays(self::RETENTION_DAYS),
        );
    }

    public function clear(int $userId): void
    {
        $this->cache->forget($this->key($userId));
    }

    public function lastFailure(int $userId): ?FxRefreshFailure
    {
        $stored = $this->cache->get($this->key($userId));

        if (! is_array($stored)) {
            return null;
        }

        $reason = FxRefreshFailureReason::tryFrom(is_string($stored['reason'] ?? null) ? $stored['reason'] : '');
        $at = $stored['at'] ?? null;

        if ($reason === null || ! is_string($at)) {
            return null;
        }

        return new FxRefreshFailure($reason, CarbonImmutable::parse($at));
    }

    private function key(int $userId): string
    {
        return self::KEY_PREFIX.$userId.self::KEY_SUFFIX;
    }
}
