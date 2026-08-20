<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Public\Contracts\Clock;
use Modules\DevMode\Internal\Audit\DevModeActivity;

// Manual-only: audit history is retained forever by policy, so this is never
// scheduled and `--older-than` has no default — the operator names the cutoff.
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
        $days = $this->resolveRetentionDays();
        if ($days === null) {
            return self::FAILURE;
        }

        $cutoff = $this->clock->now()->subDays($days);

        $deletedRaw = DevModeActivity::query()
            ->where('log_name', 'dev_mode')
            ->where('created_at', '<', $cutoff)
            ->delete();
        /** @var int|mixed $deletedRaw */
        $deleted = is_int($deletedRaw) ? $deletedRaw : 0;

        $this->info("Pruned {$deleted} dev_mode_audit row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function resolveRetentionDays(): ?int
    {
        $raw = $this->option('older-than');

        if (! is_string($raw) || $raw === '') {
            $this->error('--older-than is required (positive integer of days).');

            return null;
        }

        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw <= 0) {
            $this->error('--older-than must be a positive integer of days.');

            return null;
        }

        return (int) $raw;
    }
}
