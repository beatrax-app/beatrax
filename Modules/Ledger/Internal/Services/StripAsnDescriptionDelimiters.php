<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\RowChunk;
use Modules\Ingestion\Public\Asn\AsnDescriptionDelimiters;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Internal\Enums\BackfillPass;
use Modules\Ledger\Internal\Support\SweptRowSummary;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md
 */
final class StripAsnDescriptionDelimiters
{
    use CoercesScalars;

    private const string TABLE = 'transactions';

    private const string COLUMN = 'description';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        // A factory, not the session itself: this runs from a migration, and
        // resolving a session builds the encrypter, which an install with no
        // work to do should never pay for.
        private readonly SessionFactory $session,
        private readonly EncryptionMigrationService $encryption,
        private readonly SearchIndexWriterContract $searchIndex,
        private readonly BackfillCompletionMarkers $markers,
        private readonly AsnCsvRowSummary $summary,
        private readonly LoggerInterface $log,
    ) {}

    // Every owner at once, for the caller that has no particular user in hand.
    // On an install whose ledger is sealed this converts nobody and marks
    // nobody; sweepPendingFor() is where those users are reached.
    public function run(): int
    {
        $userIds = $this->ownersOfAsnCsvRows($this->db->connection());
        if ($userIds === []) {
            return 0;
        }

        $session = ($this->session)();
        $rewritten = 0;

        foreach ($userIds as $userId) {
            $rewritten += $this->sweepPendingFor($userId, $session);
        }

        return $rewritten;
    }

    // The one entry point that is safe to call on every unlock. A settled user
    // costs the marker read plus one aggregate, and neither reads a
    // description, so nothing here has to open the ledger to find no work.
    public function sweepPendingFor(int $userId, Session $session): int
    {
        $current = $this->summary->for($userId);

        // Three ways to sweep nothing, and each leaves a different trace: an
        // already-settled marker is left alone, an empty set is marked answered
        // so the next unlock does not look again, and a sealed-out set is
        // reported rather than recorded, because it has NOT been answered.
        if ($this->noWorkToDo($userId, $current, $session)) {
            return 0;
        }

        $rewritten = $this->sweepUser($this->db->connection(), $userId, $session);

        // Marked against the set read BEFORE the sweep, never a fresh reading.
        // A row that arrived while the pass was running is then still unseen,
        // and the next unlock picks it up instead of it being recorded as
        // answered by a pass that never looked at it.
        $this->markComplete($userId, $current);

        return $rewritten;
    }

    private function noWorkToDo(int $userId, SweptRowSummary $current, Session $session): bool
    {
        if ($this->isSettled($userId, $current)) {
            return true;
        }

        if ($current->isEmpty()) {
            $this->markComplete($userId, $current);

            return true;
        }

        $sealedOut = $this->encryption->isEnabled($userId) && ! $this->codec->canSeal($userId, $session);
        if ($sealedOut) {
            $this->reportSkipped($userId);
        }

        return $sealedOut;
    }

    private function isSettled(int $userId, SweptRowSummary $current): bool
    {
        $completed = $this->markers->completedSummary($userId, BackfillPass::AsnDescriptionDelimiters);

        return $completed !== null && $completed->equals($current);
    }

    private function markComplete(int $userId, SweptRowSummary $swept): void
    {
        $this->markers->markComplete($userId, BackfillPass::AsnDescriptionDelimiters, $swept);
    }

    // Only `asn-csv` reaches AsnCsvAdapter: it is the sole key the adapter
    // registry binds to it, at every shipped release. A row with no owner is
    // out of reach either way — the codec keys on a user, and the index writer
    // verifies the actor against the row's owner.
    /**
     * @return list<int>
     */
    private function ownersOfAsnCsvRows(ConnectionInterface $connection): array
    {
        $ids = [];

        foreach (
            $connection->table(self::TABLE)
                ->distinct()
                ->whereNotNull('user_id')
                ->where('source_format', SourceFormat::AsnCsv->value)
                ->orderBy('user_id')
                ->pluck('user_id') as $value
        ) {
            $userId = self::toPositiveIntOrNull($value);
            if ($userId !== null) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }

    // Encryption is enabled and this process holds no app-lock key, so every
    // description of theirs is ciphertext here. Rewriting it would corrupt the
    // column and re-indexing it would put base64 in the search body; the rows
    // are left exactly as they are instead.
    private function reportSkipped(int $userId): void
    {
        $this->log->warning(
            'StripAsnDescriptionDelimiters: skipped a sealed ledger this process cannot open.',
            ['userId' => $userId, 'sourceFormat' => SourceFormat::AsnCsv->value],
        );
    }

    private function sweepUser(ConnectionInterface $connection, int $userId, Session $session): int
    {
        $rewritten = 0;

        $connection->table(self::TABLE)
            ->select(['id', self::COLUMN])
            ->where('user_id', $userId)
            ->where('source_format', SourceFormat::AsnCsv->value)
            ->orderBy('id')
            ->chunkById(
                RowChunk::DEFAULT_SIZE,
                function (Collection $rows) use ($connection, $userId, $session, &$rewritten): void {
                    $rewritten += $this->rewriteChunk($connection, $rows, $userId, $session);
                },
                'id',
            );

        return $rewritten;
    }

    // One bounded transaction per chunk, ledger write and index write together,
    // so a crash mid-sweep can never leave a row whose search document still
    // carries the quotes the ledger no longer has.
    /**
     * @param  Collection<int, stdClass>  $rows
     */
    private function rewriteChunk(
        ConnectionInterface $connection,
        Collection $rows,
        int $userId,
        Session $session,
    ): int {
        $updates = $this->updatesFor($rows, $userId, $session);
        if ($updates === []) {
            return 0;
        }

        $connection->transaction(function () use ($connection, $updates, $userId): void {
            foreach ($updates as $id => $value) {
                $connection->table(self::TABLE)->where('id', $id)->update([self::COLUMN => $value]);
                $this->searchIndex->upsertForTransaction($id, $userId);
            }
        });

        return count($updates);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<int, string|null>
     */
    private function updatesFor(Collection $rows, int $userId, Session $session): array
    {
        $updates = [];

        foreach ($rows as $row) {
            $id = self::toPositiveIntOrNull($row->id);
            $stored = self::toStringOrNull($row->description);
            if ($id === null || $stored === null || $stored === '') {
                continue;
            }

            // An empty plaintext out of a non-empty stored value is the codec
            // blanking ciphertext no epoch opened; writing the unwrap of that
            // would replace a sealed description with nothing.
            $plain = $this->codec->decryptValue(self::TABLE, self::COLUMN, $stored, $userId, $session)['value'];
            if ($plain === '') {
                continue;
            }

            $unwrapped = AsnDescriptionDelimiters::unwrapStored($plain);
            if ($unwrapped === $plain) {
                continue;
            }

            $updates[$id] = $unwrapped === null
                ? null
                : $this->codec->encryptValue(self::TABLE, self::COLUMN, $unwrapped, $userId, $session);
        }

        return $updates;
    }
}
