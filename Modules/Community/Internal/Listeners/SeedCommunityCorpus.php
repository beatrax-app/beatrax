<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Events\UserInstalled;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Idempotent UserInstalled listener that loads the bundled merchant
 * corpus + built-in heuristics from YAML and upserts each row into
 * `community_merchant_mappings` keyed on `(pattern, user_id IS NULL)`.
 * Mirrors `Modules\Categorization\Internal\Listeners\SeedDefaultCategoryTree`
 * in shape: dispatched on every signup AND on every re-run of the
 * install command, so listeners MUST tolerate re-dispatch without
 * producing duplicate rows.
 *
 * Failure handling is per-entry: a Throwable raised by the underlying
 * `updateOrInsert` call (e.g. a constraint violation we could not
 * predict) is logged at `warning` and the loop continues with the next
 * entry. The bundled YAML is project-controlled and audited at PR time,
 * so per-row failure is a defensive measure, not the expected steady
 * state.
 */
final class SeedCommunityCorpus
{
    public function __construct(
        private readonly CorpusLoader $loader,
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {}

    public function handle(UserInstalled $event): void
    {
        unset($event);

        $entries = $this->loader->loadBundled();
        $now = $this->clock->now()->toDateTimeString();

        $connection = $this->db->connection();

        foreach ($entries as $entry) {
            try {
                // updateOrInsert keyed on (pattern, user_id IS NULL) is the
                // canonical idempotent shape: re-dispatch updates the
                // friendly name / category / region / contributor in place
                // without producing duplicates. The created_at column is
                // managed by the per-table query builder defaults +
                // composite (pattern, user_id) UNIQUE — the timestamp
                // refresh on update is documented and acceptable for a
                // seed surface.
                $connection->table('community_merchant_mappings')->updateOrInsert(
                    ['pattern' => $entry->pattern, 'user_id' => null],
                    [
                        'generalized_pattern' => $entry->generalizedPattern,
                        'name' => $entry->name,
                        'category' => $entry->category,
                        'region' => $entry->region,
                        'contributor' => $entry->contributor,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            } catch (Throwable $e) {
                $this->logger->warning('SeedCommunityCorpus: skipped malformed entry.', [
                    'pattern' => $entry->pattern,
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }
    }
}
