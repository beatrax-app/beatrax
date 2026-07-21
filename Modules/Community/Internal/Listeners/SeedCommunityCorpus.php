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
 * @link ../../../../.docs/features/community/architecture.md
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
                // Check-then-branch (not updateOrInsert) keyed on (pattern,
                // user_id IS NULL): created_at is written only on the INSERT
                // side, preserving the original-seed timestamp across a
                // re-dispatch of this idempotent listener.
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
