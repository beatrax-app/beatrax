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
        if ($outcome->status === 'collided') {
            $this->reportCollisions($outcome);

            return self::FAILURE;
        }

        // A status with no message is one this command does not know about,
        // which is a failure rather than a silent success.
        $message = match ($outcome->status) {
            'noop' => sprintf('0 rows would be re-derived (already on v%d).', $outcome->targetVersion),
            'dry_run' => sprintf('Dry-run OK. %d rows would be re-derived to v%d.', $outcome->rowsAffected, $outcome->targetVersion),
            'applied' => sprintf('Re-derived %d rows to v%d.', $outcome->rowsAffected, $outcome->targetVersion),
            default => null,
        };

        if ($message === null) {
            $this->error(sprintf('Unknown re-derive outcome: %s', $outcome->status));

            return self::FAILURE;
        }

        $this->info($message);

        return self::SUCCESS;
    }

    private function reportCollisions(FingerprintRederiveOutcome $outcome): void
    {
        $json = json_encode($outcome->collisions, JSON_PRETTY_PRINT);

        $this->error(sprintf('Fingerprint v%d migration ABORTED.', $outcome->targetVersion));
        $this->error(sprintf('%d collision(s) detected:', count($outcome->collisions)));
        $this->line(is_string($json) ? $json : '[json_encode failed]');
        $this->error('Existing rows left intact. Manual reconciliation required before re-running.');
    }
}
