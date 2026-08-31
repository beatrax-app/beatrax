<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Listeners;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\RowChunk;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

final readonly class SeedCommunityCorpus
{
    public function __construct(
        private CorpusLoader $loader,
        private DatabaseManager $db,
        private LoggerInterface $logger,
        private Clock $clock,
    ) {}

    // One INSERT per row costs one implicit transaction per entry — thousands
    // of fsyncs, and the slowest thing that happens during signup.
    private const int INSERT_CHUNK = RowChunk::DEFAULT_SIZE;

    public function handle(UserInstalled $event): void
    {
        unset($event);

        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        // One query for the whole global tier: this id map answers the same
        // "insert or update?" question, and is what lets the inserts batch.
        $existing = $this->existingIds($connection);

        $pending = [];
        foreach ($this->loader->loadBundled() as $entry) {
            $existingId = $existing[$entry->pattern] ?? null;
            if ($existingId !== null) {
                $this->update($connection, $existingId, $entry, $now);

                continue;
            }

            // A corpus shipping the same pattern twice would otherwise poison
            // the whole chunk it lands in, not just itself.
            if (isset($pending[$entry->pattern])) {
                continue;
            }

            $pending[$entry->pattern] = self::mutableColumns($entry) + [
                'pattern' => $entry->pattern,
                'user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($pending), self::INSERT_CHUNK) as $chunk) {
            $this->insertChunk($connection, $chunk);
        }
    }

    /**
     * @return array<string, int> pattern => id, for the global tier only
     */
    private function existingIds(Connection $connection): array
    {
        // is_numeric, not is_int: only SQLite hands back a native int here, and
        // a strict check would silently empty this map on the other drivers
        // and re-insert the entire corpus.
        /** @var iterable<stdClass> $rows */
        $rows = $connection->table('community_merchant_mappings')->whereNull('user_id')->get(['id', 'pattern']);

        $map = [];
        foreach ($rows as $row) {
            if (is_string($row->pattern) && is_numeric($row->id)) {
                $map[$row->pattern] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * @param  list<array<string, string|int|null>>  $chunk
     */
    private function insertChunk(Connection $connection, array $chunk): void
    {
        try {
            $connection->table('community_merchant_mappings')->insert($chunk);
        } catch (Throwable $e) {
            // A chunk fails as a unit, so retry row-at-a-time: one malformed
            // entry must not cost the other 499 their seed.
            $this->logger->warning('SeedCommunityCorpus: batch insert failed, retrying row by row.', SafeExceptionContext::describe($e));

            foreach ($chunk as $row) {
                $this->insertRow($connection, $row);
            }
        }
    }

    /**
     * @param  array<string, string|int|null>  $row
     */
    private function insertRow(Connection $connection, array $row): void
    {
        try {
            $connection->table('community_merchant_mappings')->insert($row);
        } catch (Throwable $e) {
            $this->logger->warning('SeedCommunityCorpus: skipped malformed entry.', [
                'pattern' => $row['pattern'] ?? null,
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }

    private function update(Connection $connection, int $id, CorpusEntryDto $entry, string $now): void
    {
        // created_at is deliberately absent, preserving the original-seed
        // timestamp across a re-dispatch of this idempotent listener.

        // Contact columns are written nulls included, so a field a contributor
        // removes from the YAML is cleared, not left as a stale cancel link.
        try {
            $connection->table('community_merchant_mappings')
                ->where('id', $id)
                ->update(self::mutableColumns($entry) + ['updated_at' => $now]);
        } catch (Throwable $e) {
            $this->logger->warning('SeedCommunityCorpus: skipped malformed entry.', [
                'pattern' => $entry->pattern,
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private static function mutableColumns(CorpusEntryDto $entry): array
    {
        $contact = $entry->contact;

        return [
            'generalized_pattern' => $entry->generalizedPattern,
            'name' => $entry->name,
            'category' => $entry->category,
            'region' => $entry->region,
            'contributor' => $entry->contributor,
            'website' => $contact?->website,
            'cancel_url' => $contact?->cancelUrl,
            'support_url' => $contact?->supportUrl,
            'support_phone' => $contact?->supportPhone,
            'support_email' => $contact?->supportEmail,
        ];
    }
}
