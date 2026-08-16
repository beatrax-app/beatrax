<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Listeners;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Events\UserInstalled;
use Psr\Log\LoggerInterface;
use stdClass;
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

    // Chunked so a first install writes the bundled corpus in a bounded number
    // of statements. At one INSERT per row the corpus costs as many implicit
    // transactions as it has entries — thousands of fsyncs, and the slowest
    // thing that happens during signup.
    private const INSERT_CHUNK = 500;

    public function handle(UserInstalled $event): void
    {
        unset($event);

        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        // One query for the whole existing global tier rather than a lookup per
        // entry: the id map answers the same "insert or update?" question the
        // per-row SELECT did, and it is what lets the inserts batch at all.
        $existing = $this->existingIds($connection);

        $pending = [];
        foreach ($this->loader->loadBundled() as $entry) {
            $existingId = $existing[$entry->pattern] ?? null;
            if ($existingId !== null) {
                $this->update($connection, $existingId, $entry, $now);

                continue;
            }

            // Guards the UNIQUE index against a corpus that ships the same
            // pattern twice: the second copy would otherwise poison the whole
            // chunk it lands in rather than just itself.
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
        // `id` is read through is_numeric rather than is_int because only
        // SQLite hands back a native int here; the MySQL and Postgres PDO
        // drivers return the same value as a string, and a strict check would
        // silently empty this map and re-insert the entire corpus.
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
            // A chunk fails as a unit, so fall back to row-at-a-time to keep
            // the original guarantee: one malformed entry is skipped and
            // logged, it never costs the other 499 their seed.
            $this->logger->warning('SeedCommunityCorpus: batch insert failed, retrying row by row.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

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
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    private function update(Connection $connection, int $id, CorpusEntryDto $entry, string $now): void
    {
        // created_at is deliberately absent, preserving the original-seed
        // timestamp across a re-dispatch of this idempotent listener.

        // The contact columns ARE written, nulls included, so a field a
        // contributor removes from the YAML is cleared rather than lingering
        // as a stale cancellation link.
        try {
            $connection->table('community_merchant_mappings')
                ->where('id', $id)
                ->update(self::mutableColumns($entry) + ['updated_at' => $now]);
        } catch (Throwable $e) {
            $this->logger->warning('SeedCommunityCorpus: skipped malformed entry.', [
                'pattern' => $entry->pattern,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
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
