<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The lifecycle shared by the async "run" rows (forecast_runs,
// chain_resolution_runs): queued `pending`, flips to `running`, finishes
// `complete` or `failed`. Columns stay string; this enum owns the
// vocabulary and the transition graph the per-run guards enforce.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
enum JobRunStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Complete = 'complete';

    case Failed = 'failed';

    // A run can fail from either non-terminal state; complete and failed
    // are terminal (no legal successor).
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Failed],
            self::Running => [self::Complete, self::Failed],
            self::Complete => [],
            self::Failed => [],
        };
    }
}
