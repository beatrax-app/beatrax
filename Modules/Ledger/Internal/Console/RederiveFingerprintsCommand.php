<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Console;

use Illuminate\Console\Command;
use Modules\Ledger\Internal\Services\FingerprintRederiveOutcome;
use Modules\Ledger\Internal\Services\FingerprintRederiveService;

// Re-computes the sha256 fingerprint of every transactions row using
// the current FingerprintComposer normalization version, via
// FingerprintRederiveService; guarded against HTTP invocation by
// LedgerServiceProvider's runningInConsole() check plus a BoundaryArchTest rule.
final class RederiveFingerprintsCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:rederive-fingerprints
        {--confirm : Apply the update inside a single DB transaction.}
        {--dry-run : Compute the new fingerprints in memory and report without writing.}';

    /** @var string */
    protected $description = 'Re-compute the SHA-256 fingerprint of every transactions row using the current FingerprintComposer NORMALIZATION_VERSION. Aborts cleanly if the new tuple would collide on existing data.';

    public function __construct(
        private readonly FingerprintRederiveService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run') === true;
        $isConfirmed = $this->option('confirm') === true;

        $outcome = $this->service->run(apply: $isConfirmed && ! $isDryRun);

        return $this->report($outcome);
    }

    private function report(FingerprintRederiveOutcome $outcome): int
    {
        switch ($outcome->status) {
            case 'collided':
                $count = count($outcome->collisions);
                $json = json_encode($outcome->collisions, JSON_PRETTY_PRINT);

                $this->error(sprintf('Fingerprint v%d migration ABORTED.', $outcome->targetVersion));
                $this->error(sprintf('%d collision(s) detected:', $count));
                $this->line(is_string($json) ? $json : '[json_encode failed]');
                $this->error('Existing rows left intact. Manual reconciliation required before re-running.');

                return self::FAILURE;

            case 'noop':
                $this->info(sprintf('0 rows would be re-derived (already on v%d).', $outcome->targetVersion));

                return self::SUCCESS;

            case 'dry_run':
                $this->info(sprintf('Dry-run OK. %d rows would be re-derived to v%d.', $outcome->rowsAffected, $outcome->targetVersion));

                return self::SUCCESS;

            case 'applied':
                $this->info(sprintf('Re-derived %d rows to v%d.', $outcome->rowsAffected, $outcome->targetVersion));

                return self::SUCCESS;
        }

        $this->error(sprintf('Unknown re-derive outcome: %s', $outcome->status));

        return self::FAILURE;
    }
}
