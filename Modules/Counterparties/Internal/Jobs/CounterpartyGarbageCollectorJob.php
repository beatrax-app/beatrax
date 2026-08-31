<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\LockStore;
use Modules\Core\Public\Support\RetentionWindow;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @link ../../../../.docs/features/counterparties/garbage-collection.md
 */
final class CounterpartyGarbageCollectorJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return Duration::Hour->seconds();
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        ?Session $session = null,
        ?AppLockKeyService $appLockKeyService = null,
        ?EncryptionMigrationService $encryptionMigrationService = null,
        ?SensitiveColumnCodec $codec = null,
        ?LoggerInterface $logger = null,
        ?Dispatcher $events = null,
    ): void {
        $connection = $db->connection();
        $cutoff = RetentionWindow::cutoff($clock);
        [$isEncrypted, $canDecryptMerchantName] = $this->resolveEncryptionContext(
            $session,
            $appLockKeyService,
            $encryptionMigrationService,
        );

        $connection->transaction(function () use ($connection, $cutoff, $isEncrypted, $canDecryptMerchantName, $session, $codec, $logger, $events): void {
            $orphans = $this->collectOrphans($connection, $cutoff, $isEncrypted, $canDecryptMerchantName, $session, $codec, $logger);
            if ($orphans === []) {
                return;
            }

            $this->pruneOrphans($connection, $orphans, $events);
        });
    }

    // The short call shape leaves all three collaborators null, so it defaults
    // to "not encrypted" rather than gating on a KEK it was never handed.
    /**
     * @return array{0: bool, 1: bool} [isEncrypted, canDecryptMerchantName]
     */
    private function resolveEncryptionContext(
        ?Session $session,
        ?AppLockKeyService $appLockKeyService,
        ?EncryptionMigrationService $encryptionMigrationService,
    ): array {
        if ($session === null || $appLockKeyService === null || $encryptionMigrationService === null) {
            return [false, false];
        }

        $isEncrypted = $encryptionMigrationService->isEnabled($this->userId);
        $hasKek = $appLockKeyService->release($session) !== null;

        return [$isEncrypted, $isEncrypted && $hasKek];
    }

    /**
     * @return list<int>
     */
    private function collectOrphans(
        ConnectionInterface $connection,
        string $cutoff,
        bool $isEncrypted,
        bool $canDecryptMerchantName,
        ?Session $session,
        ?SensitiveColumnCodec $codec,
        ?LoggerInterface $logger,
    ): array {
        $orphans = $this->collectNullNameOrphans($connection, $cutoff);

        if (! $isEncrypted) {
            return [...$orphans, ...$this->collectPlaintextNamedOrphans($connection, $cutoff)];
        }

        if ($canDecryptMerchantName && $session !== null && $codec !== null) {
            return [...$orphans, ...$this->collectDecryptedNamedOrphans($connection, $cutoff, $session, $codec)];
        }

        $this->logSkippedEncryptedHalf($connection, $cutoff, $logger);

        return $orphans;
    }

    // The window is measured on transactions.created_at (row insert time), not
    // posted_at, so re-importing an old statement re-arms retention for its
    // counterparty.
    private function notRecentlyTransacted(Builder $query, string $cutoff): void
    {
        $query
            ->select(new Expression('1'))
            ->from('transactions')
            ->whereColumn('transactions.counterparty_id', 'counterparties.id')
            ->where('transactions.user_id', $this->userId)
            ->where('transactions.created_at', '>=', $cutoff);
    }

    // A NULL column is never turned into ciphertext, so this half of the
    // predicate prunes unconditionally, encrypted or not.
    /**
     * @return list<int>
     */
    private function collectNullNameOrphans(ConnectionInterface $connection, string $cutoff): array
    {
        $ids = $connection
            ->table('counterparties')
            ->where('counterparties.user_id', $this->userId)
            ->whereNotExists(function (Builder $query) use ($cutoff): void {
                $this->notRecentlyTransacted($query, $cutoff);
            })
            ->whereNull('counterparties.merchant_name')
            ->pluck('counterparties.id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (int|float|string $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    /**
     * @return list<int>
     */
    private function collectPlaintextNamedOrphans(ConnectionInterface $connection, string $cutoff): array
    {
        $ids = $connection
            ->table('counterparties')
            ->where('counterparties.user_id', $this->userId)
            ->whereNotExists(function (Builder $query) use ($cutoff): void {
                $this->notRecentlyTransacted($query, $cutoff);
            })
            ->whereNotNull('counterparties.merchant_name')
            ->whereNotExists(function (Builder $query): void {
                $query
                    ->select(new Expression('1'))
                    ->from('merchant_aliases')
                    ->whereColumn(
                        'merchant_aliases.friendly_name',
                        'counterparties.merchant_name',
                    )
                    ->where('merchant_aliases.user_id', $this->userId);
            })
            ->pluck('counterparties.id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (int|float|string $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    // AEAD ciphertext can never byte-equal plaintext, so the alias equality
    // cannot run in SQL once merchant_name is encrypted.
    /**
     * @return list<int>
     */
    private function collectDecryptedNamedOrphans(
        ConnectionInterface $connection,
        string $cutoff,
        Session $session,
        SensitiveColumnCodec $codec,
    ): array {
        /** @var list<string> $aliasNames */
        $aliasNames = $connection
            ->table('merchant_aliases')
            ->where('user_id', $this->userId)
            ->pluck('friendly_name')
            ->filter(static fn (mixed $name): bool => is_string($name))
            ->values()
            ->all();

        $candidates = $connection
            ->table('counterparties')
            ->where('counterparties.user_id', $this->userId)
            ->whereNotExists(function (Builder $query) use ($cutoff): void {
                $this->notRecentlyTransacted($query, $cutoff);
            })
            ->whereNotNull('counterparties.merchant_name')
            ->get(['id', 'merchant_name']);

        $orphans = [];
        foreach ($candidates as $candidate) {
            $id = $this->decryptedOrphanId($candidate, $aliasNames, $session, $codec);
            if ($id !== null) {
                $orphans[] = $id;
            }
        }

        return $orphans;
    }

    // decryptValue never throws: a failure returns raw ciphertext with
    // decrypted:false, which would never match an alias and would prune an
    // alias-protected row. Skip that candidate instead.
    /**
     * @param  list<string>  $aliasNames
     */
    private function decryptedOrphanId(
        stdClass $candidate,
        array $aliasNames,
        Session $session,
        SensitiveColumnCodec $codec,
    ): ?int {
        $rawId = $candidate->id;
        $id = is_numeric($rawId) ? (int) $rawId : null;
        if ($id === null || ! is_string($candidate->merchant_name)) {
            return null;
        }

        $result = $codec->decryptValue(
            'counterparties',
            'merchant_name',
            $candidate->merchant_name,
            $this->userId,
            $session,
        );
        if (! $result['decrypted']) {
            return null;
        }

        return in_array($result['value'], $aliasNames, true) ? null : $id;
    }

    // Encrypted with no KEK, the alias half is skipped rather than guessed
    // at — never a wrongful prune. The candidates are re-evaluated on a
    // later run that has one.
    private function logSkippedEncryptedHalf(ConnectionInterface $connection, string $cutoff, ?LoggerInterface $logger): void
    {
        if ($logger === null) {
            return;
        }

        $skippedCount = $connection
            ->table('counterparties')
            ->where('counterparties.user_id', $this->userId)
            ->whereNotExists(function (Builder $query) use ($cutoff): void {
                $this->notRecentlyTransacted($query, $cutoff);
            })
            ->whereNotNull('counterparties.merchant_name')
            ->count();

        if ($skippedCount > 0) {
            $logger->warning(
                'CounterpartyGarbageCollectorJob: no app-lock KEK available for an encrypted user in this run — merchant_name/alias-dependent prune skipped for candidate counterparties; they will be re-evaluated on a future run with an available KEK.',
                ['user_id' => $this->userId, 'skipped_count' => $skippedCount],
            );
        }
    }

    // transactions.counterparty_id carries no ON DELETE cascade, so the FK is
    // NULLed before the DELETE: the history row stays, only the link goes.
    // Both writes are announced — without that a GC run on one device left the
    // peer holding rows this device deleted and links this device broke.
    /**
     * @param  list<int>  $orphans
     */
    private function pruneOrphans(ConnectionInterface $connection, array $orphans, ?Dispatcher $events): void
    {
        $unlinked = $this->transactionIdsLinkedTo($connection, $orphans);

        $connection
            ->table('transactions')
            ->where('user_id', $this->userId)
            ->whereIn('counterparty_id', $orphans)
            ->update(['counterparty_id' => null]);

        $connection
            ->table('counterparties')
            ->where('user_id', $this->userId)
            ->whereIn('id', $orphans)
            ->delete();

        if ($events === null) {
            return;
        }

        // Broken links first: a peer that saw the delete before the unlink
        // would replay a transaction still pointing at a row it just dropped.
        foreach ($unlinked as $transactionId) {
            $events->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $this->userId,
                mutationType: 'edit',
                dirtyFields: ['counterparty_id' => null],
            ));
        }

        foreach ($orphans as $orphanId) {
            $events->dispatch(new EntityMutated(
                table: 'counterparties',
                pk: $orphanId,
                userId: $this->userId,
                mutationType: 'delete',
            ));
        }
    }

    /**
     * @param  list<int>  $orphans
     * @return list<int>
     */
    private function transactionIdsLinkedTo(ConnectionInterface $connection, array $orphans): array
    {
        $ids = $connection
            ->table('transactions')
            ->where('user_id', $this->userId)
            ->whereIn('counterparty_id', $orphans)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (int|float|string $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }
}
