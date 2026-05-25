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
                // Idempotent shape keyed on (pattern, user_id IS NULL):
                // re-dispatch updates the mutable fields in place
                // without producing duplicates. The check-then-branch
                // is preferred over updateOrInsert here so created_at
                // is written only on the INSERT side — preserving the
                // original-seed timestamp for audit even when the
                // install command re-emits UserInstalled later.
                $existingId = $connection->table('community_merchant_mappings')
                    ->where('pattern', $entry->pattern)
                    ->whereNull('user_id')
                    ->value('id');

                if ($existingId === null) {
                    $connection->table('community_merchant_mappings')->insert([
                        'pattern' => $entry->pattern,
                        'user_id' => null,
                        'generalized_pattern' => $entry->generalizedPattern,
                        'name' => $entry->name,
                        'category' => $entry->category,
                        'region' => $entry->region,
                        'contributor' => $entry->contributor,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $connection->table('community_merchant_mappings')
                        ->where('id', $existingId)
                        ->update([
                            'generalized_pattern' => $entry->generalizedPattern,
                            'name' => $entry->name,
                            'category' => $entry->category,
                            'region' => $entry->region,
                            'contributor' => $entry->contributor,
                            'updated_at' => $now,
                        ]);
                }
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
