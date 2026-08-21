<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\RowChunk;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// The designed recovery tool for any desync between transactions and
// transaction_search_docs/transaction_search_fts. Chunked in batches
// of 500 to avoid OOM on multi-year datasets. SearchIndexWriter is the
// synchronous path for normal writes; this is the full-rebuild path.
final class ReindexSearchCommand extends Command
{
    /** @var string */
    protected $signature = 'search:reindex {--force : Skip the confirmation prompt.}';

    /** @var string */
    protected $description = 'Rebuild the FTS5 full-text search index from all transactions.';

    private const CHUNK_SIZE = RowChunk::DEFAULT_SIZE;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        // A factory, not the session itself: resolving a session builds the
        // encrypter, and Artisan constructs every command merely to list it.
        private readonly SessionFactory $session,
        private readonly EncryptionMigrationService $encryptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->db->connection();
        $session = ($this->session)();

        [$rebuildable, $blocked] = $this->partitionUsers($connection, $session);

        if ($blocked !== []) {
            $this->reportBlocked($blocked);
        }

        // Nothing indexable: return before clearIndexFor(), so a refusal
        // never leaves the index emptier than it found it.
        if ($rebuildable === []) {
            $this->error('FTS reindex aborted: no transactions this process can index.');

            return self::FAILURE;
        }

        $this->clearIndexFor($connection, $rebuildable);

        $total = $connection->table('transactions')->whereIn('user_id', $rebuildable)->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $indexed = 0;
        $connection
            ->table('transactions')
            ->select(['id', 'user_id', 'counterparty_name', 'description'])
            ->whereIn('user_id', $rebuildable)
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function (Collection $rows) use ($connection, $session, $bar, &$indexed): void {
                $indexed += $this->indexChunk($connection, $rows, $session);
                $bar->advance($rows->count());
            });

        $bar->finish();
        $this->newLine();

        // External-content FTS5: 'rebuild' regenerates every posting from
        // transaction_search_docs, so a skipped user's untouched docs rows
        // come back exactly as they were.
        $connection->statement(
            "INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('rebuild')",
        );

        return $this->reportOutcome($indexed, $total, $blocked);
    }

    // A user is rebuildable when their columns are plaintext at rest, or when
    // this process holds the key material to read them. Anything else would
    // index ciphertext, so it is refused rather than written.
    /**
     * @return array{0: list<int>, 1: list<int>}
     */
    private function partitionUsers(ConnectionInterface $connection, Session $session): array
    {
        $rebuildable = [];
        $blocked = [];

        foreach ($connection->table('transactions')->distinct()->pluck('user_id') as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $userId = (int) $value;
            $ok = ! $this->encryptionService->isEnabled($userId) || $this->holdsKeyMaterialFor($userId, $session);

            if ($ok) {
                $rebuildable[$userId] = $userId;

                continue;
            }

            $blocked[$userId] = $userId;
        }

        return [array_values($rebuildable), array_values($blocked)];
    }

    // encryptValue() is a documented pass-through whenever no epoch is usable,
    // so a probe that comes back changed proves this process holds the same
    // keyring decryptValue() needs. Both gate on the app-lock key.
    private function holdsKeyMaterialFor(int $userId, Session $session): bool
    {
        $probe = 'fts-reindex-key-probe';

        return $this->codec->encryptValue('transactions', 'description', $probe, $userId, $session) !== $probe;
    }

    /**
     * @param  list<int>  $blocked
     */
    private function reportBlocked(array $blocked): void
    {
        $this->error(sprintf(
            'FTS reindex skipped user %s: encryption at rest is enabled and this process holds no app-lock key, so '.
            'counterparty_name/description would be indexed as ciphertext. Their index rows were left untouched. '.
            'A console run never holds that key — rebuild those users from the unlocked app.',
            implode(', ', $blocked),
        ));
    }

    // truncate() fails on SQLite tables without sqlite_sequence (no
    // AUTOINCREMENT); DELETE is equivalent for our purpose. Scoped to the
    // users about to be rebuilt — a skipped user keeps their docs rows.
    /**
     * @param  list<int>  $userIds
     */
    private function clearIndexFor(ConnectionInterface $connection, array $userIds): void
    {
        $connection->table('transaction_search_docs')->whereIn('user_id', $userIds)->delete();
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return int number of docs written for this chunk
     */
    private function indexChunk(ConnectionInterface $connection, Collection $rows, Session $session): int
    {
        $ids = $rows->pluck('id')->all();

        $notesByTxId = [];
        foreach ($connection->table('tax_transaction_tags')->select(['transaction_id', 'note'])->whereIn('transaction_id', $ids)->get() as $tag) {
            if (is_numeric($tag->transaction_id)) {
                $notesByTxId[(int) $tag->transaction_id] = $tag->note;
            }
        }

        $docs = [];
        foreach ($rows as $row) {
            $txId = is_numeric($row->id) ? (int) $row->id : 0;
            $userId = is_numeric($row->user_id) ? (int) $row->user_id : 0;

            // Decrypted before the body is built, so FTS5 tokenizes plaintext
            // — identical treatment to SearchIndexWriter's single-row path.
            $counterparty = $this->decrypt('transactions', 'counterparty_name', $row->counterparty_name, $userId, $session);
            $description = $this->decrypt('transactions', 'description', $row->description, $userId, $session);
            $note = $this->decrypt('tax_transaction_tags', 'note', $notesByTxId[$txId] ?? null, $userId, $session);

            // Indexing a blank body would claim success over a row that can no
            // longer be found at all. Left out instead, so the count comes up
            // short and reportOutcome() says the rebuild is incomplete.
            if ($counterparty === null || $description === null || $note === null) {
                continue;
            }

            $docs[] = [
                'transaction_id' => $txId,
                'user_id' => $userId,
                'search_body' => $counterparty.chr(12).$description.chr(12).$note,
            ];
        }

        if ($docs !== []) {
            $connection->table('transaction_search_docs')->insert($docs);
        }

        return count($docs);
    }

    // Null when the codec BLANKED the value rather than handing it back: that
    // shape, and only that shape, means ciphertext no epoch in this keyring
    // opens. A plaintext row comes back untouched and indexes normally.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function decrypt(string $table, string $column, mixed $stored, int $userId, Session $session): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return '';
        }

        $result = $this->codec->decryptValue($table, $column, $stored, $userId, $session);

        return ! $result['decrypted'] && $result['value'] === '' ? null : $result['value'];
    }

    // Fail loudly on a partial run — a mismatched count means the index
    // is incomplete, and search would silently return partial results
    // otherwise. A skipped user is the same kind of incomplete.
    /**
     * @param  list<int>  $blocked
     */
    private function reportOutcome(int $indexed, int $total, array $blocked): int
    {
        if ($indexed !== $total) {
            $this->warn(
                "FTS reindex incomplete: indexed {$indexed} of {$total} transactions. ".
                'The index may be partial — re-run search:reindex.',
            );

            return self::FAILURE;
        }

        $this->info("FTS index rebuilt. {$indexed} transactions indexed.");

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }
}
