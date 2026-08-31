<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;

// What one slice of a backfill may spend before committing and leaving the rest
// for the next pass. A capture runs in a web request, where the desktop bundle's
// `-d max_execution_time=120` is a ceiling nothing can catch: the fatal is not
// throwable, so it logged nothing and committed nothing (see @link).
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md
 */
final class BackfillBudget
{
    // Measured at ~34 rows a second on a real ledger, because each row emits
    // ~34 op-log entries and each of those is an Ed25519 signature, an insert
    // and a clock write. The row bound is the granularity the walk can stop
    // at; the deadline is the one that keeps a slice inside the ceiling.
    private const int ROWS_PER_SLICE = 400;

    private const int SECONDS_PER_SLICE = 5;

    private function __construct(
        private readonly Clock $clock,
        private readonly CarbonImmutable $deadline,
        private int $rowsLeft,
    ) {}

    public static function of(Clock $clock, int $rows, int $seconds): self
    {
        return new self($clock, $clock->now()->addSeconds($seconds), $rows);
    }

    public static function forOneSlice(Clock $clock): self
    {
        return self::of($clock, self::ROWS_PER_SLICE, self::SECONDS_PER_SLICE);
    }

    public function spend(int $rows): void
    {
        $this->rowsLeft -= $rows;
    }

    public function isSpent(): bool
    {
        return $this->rowsLeft <= 0 || $this->clock->now()->greaterThanOrEqualTo($this->deadline);
    }
}
