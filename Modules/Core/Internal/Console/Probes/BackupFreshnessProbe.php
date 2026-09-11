<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Carbon\CarbonImmutable;
use Modules\Core\Internal\Backup\BackupFreshness;
use Throwable;

final readonly class BackupFreshnessProbe implements Probe
{
    private const string BACKUP_AGE_MESSAGE = 'Most recent verified backup is %dh old.';

    public function __construct(
        private BackupFreshness $freshness,
    ) {}

    public function label(): string
    {
        return 'Backup freshness';
    }

    public function run(): ProbeResult
    {
        try {
            $newestCompletedAt = $this->freshness->newestVerifiedAt();
        } catch (Throwable $e) {
            return new ProbeResult(ProbeSeverity::Critical->value,
                'Failed to read backups directory: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        return $this->freshnessOf($newestCompletedAt);
    }

    // Split from run() so the unreadable-directory case, which is about this
    // machine, stays apart from the three that are about the backups
    // themselves. Both warnings record an overdue alert; the ok result does
    // not, which is the only asymmetry worth reading closely.
    private function freshnessOf(?CarbonImmutable $newestCompletedAt): ProbeResult
    {
        if ($newestCompletedAt === null) {
            $this->freshness->raiseOverdue(null);

            return new ProbeResult(ProbeSeverity::Warning->value,
                'No verified backups found under the backups directory.',
                ['hours_old' => null],
            );
        }

        $hoursOld = $this->freshness->hoursSince($newestCompletedAt);

        if ($this->freshness->isStale($hoursOld)) {
            $this->freshness->raiseOverdue($hoursOld);

            return new ProbeResult(ProbeSeverity::Warning->value,
                sprintf(self::BACKUP_AGE_MESSAGE, $hoursOld),
                ['hours_old' => $hoursOld],
            );
        }

        return new ProbeResult(ProbeSeverity::Ok->value,
            sprintf(self::BACKUP_AGE_MESSAGE, $hoursOld),
            ['hours_old' => $hoursOld],
        );
    }
}
