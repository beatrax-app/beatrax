<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Public\Contracts\Clock;
use Modules\DevMode\Internal\Audit\DevModeActivity;

/**
 * `php artisan beatrax:prune-dev-audit --older-than=Nd` — manual prune of
 * dev_mode_audit rows older than N days.
 *
 * The project policy is "History: Full history retained forever"
 * (CLAUDE.md), so this command is NOT scheduled. It exists for the
 * operator's manual use only — e.g. after a one-off noisy test that
 * generated thousands of rows.
 *
 * Tier: SAFE (CONTEXT D-12 implies any read/cleanup over the dev_mode
 * surface is SAFE because the runner already gates on the developer
 * role). Listed in CommandRegistry as SAFE so the fallback modal can
 * surface it once 16-04b registers the page.
 *
 * Validation:
 *   - `--older-than` is required (no default — operator must opt in).
 *   - Must be a positive integer.
 *
 * Deletes via the spatie Activity model's eloquent query so the
 * configured table_name override resolves correctly.
 */
final class PruneDevAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:prune-dev-audit {--older-than= : Days to retain; rows older than this are deleted}';

    /** @var string */
    protected $description = 'Manually prune dev_mode_audit rows older than the given day count.';

    public function __construct(
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $olderThanRaw = $this->option('older-than');

        if (! is_string($olderThanRaw) || $olderThanRaw === '') {
            $this->error('--older-than is required (positive integer of days).');

            return self::FAILURE;
        }

        if (preg_match('/^\d+$/', $olderThanRaw) !== 1) {
            $this->error('--older-than must be a positive integer of days.');

            return self::FAILURE;
        }

        $days = (int) $olderThanRaw;
        if ($days <= 0) {
            $this->error('--older-than must be a positive integer of days.');

            return self::FAILURE;
        }

        $cutoff = $this->clock->now()->subDays($days);

        // Eloquent\Builder::where() returns Builder; ->delete() exists
        // on the Eloquent\Builder directly (no __call forwarding).
        $deletedRaw = DevModeActivity::query()
            ->where('log_name', 'dev_mode')
            ->where('created_at', '<', $cutoff)
            ->delete();
        /** @var int|mixed $deletedRaw */
        $deleted = is_int($deletedRaw) ? $deletedRaw : 0;

        $this->info("Pruned {$deleted} dev_mode_audit row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
