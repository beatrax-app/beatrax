<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Ledger\Internal\Services\FingerprintRederiveService;

return new class extends Migration
{
    public function up(): void
    {
        // Migration classes are anonymous and instantiated by Laravel's
        // migrator with no constructor arguments, so the FingerprintRederiveService
        // has to be resolved from the application container at the migration
        // boundary. This is the documented exception to the DI-only rule:
        // schema migrations cannot themselves receive constructor injection.
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
        // Re-deriving the current normalization_version back to its
        // predecessor is destructive: the current algorithm may have merged
        // rows that the previous one distinguished, and reversing the hash
        // would re-fragment those merged rows incorrectly. Restore from a
        // SQLite backup if a rollback is required.
    }
};
