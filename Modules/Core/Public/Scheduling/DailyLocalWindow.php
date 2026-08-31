<?php

declare(strict_types=1);

namespace Modules\Core\Public\Scheduling;

use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;

// A once-per-local-day gate for work whose wall-clock hour is part of the
// product. The phone's background runner only understands repeat intervals,
// so a task that must not fire before 09:15 ticks often and asks here.
/**
 * @link ../../../../.docs/features/mobile/architecture.md#a-wall-clock-hour-the-runner-cannot-express-moves-into-the-command
 */
final readonly class DailyLocalWindow
{
    private const string KEY_PREFIX = 'beatrax:daily-window:';

    // The entry is keyed on a local date but claimed part-way through it, so it
    // has to outlive the rest of that day — a stretch a westward timezone move
    // pushes past 24 hours. Expiring early re-opens a day already spent.
    private const int CLAIM_DAYS = 2;

    public function __construct(
        private Repository $cache,
        private Clock $clock,
    ) {}

    // The one expression for how long a claimed day stands. Work that must not
    // lapse before the claim gating it derives from here rather than restating
    // the span, which is a thing only a comment could assert and nothing check.
    public static function claimTtlSeconds(): int
    {
        return Duration::Day->seconds() * self::CLAIM_DAYS;
    }

    // Read-only, so the scheduler can ask it as a `->when()` filter without
    // consuming the day: on the desktop that is what keeps a fifteen-minute
    // entry from spawning ninety-five artisan processes it has no work for.
    public function isDue(string $key, string $notBefore): bool
    {
        $now = $this->clock->now();

        return $now->format('H:i') >= $notBefore
            && ! $this->cache->has(self::dayKey($key, $now->toDateString()));
    }

    // `add()` rather than has()-then-put(): it is the atomic half of the cache
    // contract, so two ticks racing for the same day cannot both win it.
    public function claim(string $key, string $notBefore): bool
    {
        $now = $this->clock->now();

        if ($now->format('H:i') < $notBefore) {
            return false;
        }

        return $this->cache->add(
            self::dayKey($key, $now->toDateString()),
            $now->toIso8601String(),
            self::claimTtlSeconds(),
        );
    }

    private static function dayKey(string $key, string $date): string
    {
        return self::KEY_PREFIX.$key.':'.$date;
    }
}
