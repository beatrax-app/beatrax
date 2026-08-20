<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Ledger\Internal\Services\FingerprintRederiveService;

return new class extends Migration
{
    public function up(): void
    {
        // Migrations receive no constructor injection, so container resolution here
        // is the documented exception to the DI-only rule.
        /** @var FingerprintRederiveService $service */
        $service = app(FingerprintRederiveService::class);

        $outcome = $service->run(apply: true);

        if ($outcome->isCollision()) {
            $count = count($outcome->collisions);
            $json = json_encode($outcome->collisions, JSON_PRETTY_PRINT);

            throw new RuntimeException(sprintf(
                "Fingerprint v%d re-derive migration ABORTED.\n"
                .'%d collision(s) detected; existing rows have been left on the previous '
                ."normalization_version.\n%s\n"
                .'Manual reconciliation required before re-running.',
                $outcome->targetVersion,
                $count,
                is_string($json) ? $json : '[json_encode failed]',
            ));
        }
    }

    public function down(): void
    {
        // Re-deriving back to the predecessor version is destructive: the current
        // algorithm may have merged rows the previous one distinguished, and the
        // reverse would re-fragment them incorrectly. Restore from a SQLite backup.
    }
};
