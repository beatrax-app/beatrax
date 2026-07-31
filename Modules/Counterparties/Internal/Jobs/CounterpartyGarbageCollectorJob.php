<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
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
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\LockStore;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

// This job's sole dispatch origin is the daily scheduler tick (a queue
// worker with no live Session, so never an app-lock KEK) — handle()'s
// three-way encryption branch and the two-key orphan predicate are
// documented in full at the @link below.
/**
 * @link ../../../../.docs/features/counterparties/architecture.md
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
        ?Session $session = null,
        ?AppLockKeyService $appLockKeyService = null,
        ?EncryptionMigrationService $encryptionMigrationService = null,
        ?SensitiveColumnCodec $codec = null,
        ?LoggerInterface $logger = null,
    ): void {
        $connection = $db->connection();
        [$isEncrypted, $canDecryptMerchantName] = $this->resolveEncryptionContext(
            $session,
            $appLockKeyService,
            $encryptionMigrationService,
        );

        $connection->transaction(function () use ($connection, $isEncrypted, $canDecryptMerchantName, $session, $codec, $logger): void {
            $orphans = $this->collectOrphans($connection, $isEncrypted, $canDecryptMerchantName, $session, $codec, $logger);
            if ($orphans === []) {
                return;
            }

            $this->pruneOrphans($connection, $orphans);
        });
    }

    // Only gate on KEK-absence when a real Session/AppLockKeyService/
    // EncryptionMigrationService context was resolved (production dispatch).
    // The legacy 1-arg test-call shape leaves all three null, defaulting to
    // "not encrypted" for always-plaintext fixtures.
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

    // The orphan predicate is two halves. Step 1a (NULL merchant_name)
    // always prunes; step 1b (NOT NULL) branches on encryption state — see
    // the linked doc for the full rationale.
    /**
     * @return list<int>
     */
    private function collectOrphans(
        ConnectionInterface $connection,
        bool $isEncrypted,
        bool $canDecryptMerchantName,
        ?Session $session,
        ?SensitiveColumnCodec $codec,
        ?LoggerInterface $logger,
    ): array {
        $orphans = $this->collectNullNameOrphans($connection);

        if (! $isEncrypted) {
            return [...$orphans, ...$this->collectPlaintextNamedOrphans($connection)];
        }

        if ($canDecryptMerchantName && $session !== null && $codec !== null) {
            return [...$orphans, ...$this->collectDecryptedNamedOrphans($connection, $session, $codec)];
        }

        $this->logSkippedEncryptedHalf($connection, $logger);

        return $orphans;
    }

    // SQLite-portable date arithmetic. The 365-day window mirrors the
    // long-history retention promise — a counterparty untouched for a full
    // year is a strong prune candidate.
    private function notRecentlyTransacted(Builder $query): void
    {
        $query
            ->select(new Expression('1'))
            ->from('transactions')
            ->whereColumn('transactions.counterparty_id', 'counterparties.id')
            ->where('transactions.user_id', $this->userId)
            ->whereRaw("transactions.created_at >= datetime('now', '-365 days')");
    }

    // Step 1a — the `merchant_name IS NULL` half. Always plaintext-safe: a
    // NULL column is never turned into ciphertext, so this half prunes
    // unconditionally, encrypted or not.
    /**
     * @return list<int>
     */
    private function collectNullNameOrphans(ConnectionInterface $connection): array
    {
        $ids = $connection
            ->table('counterparties')
            ->where('counterparties.user_id', $this->userId)
            ->whereNotExists($this->notRecentlyTransacted(...))
            ->whereNull('counterparties.merchant_name')
            ->pluck('counterparties.id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (int|float|string $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    // Step 1b, plaintext: both sides of the comparison are genuinely
    // plaintext, so the raw-SQL alias equality is valid.
    /**
     * @return list<int>
     */
    private function collectPlaintextNamedOrphans(ConnectionInterface $connection): array
    {
        $ids = $connection
            ->table('counterparties')
            ->where('counterparties.user_id', $this->userId)
            ->whereNotExists($this->notRecentlyTransacted(...))
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

    // Step 1b, encrypted with a KEK available: AEAD ciphertext can never
    // byte-equal plaintext at the SQL layer, so decrypt each candidate's
    // merchant_name in PHP and compare against the alias friendly_names.
    /**
     * @return list<int>
     */
    private function collectDecryptedNamedOrphans(
        ConnectionInterface $connection,
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
            ->whereNotExists($this->notRecentlyTransacted(...))
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

    // One candidate row -> its id when it is a genuine orphan, else null.
    // decryptValue never throws; a decrypt failure returns raw ciphertext
    // with decrypted:false, which would never match plaintext — skip that
    // candidate rather than risk a wrongful prune.
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

    // Step 1b, encrypted with no KEK: skip this half entirely
    // (preserve-on-uncertainty — never a wrongful prune) and, when a logger
    // is present, warn naming the user and the skipped-row count.
    private function logSkippedEncryptedHalf(ConnectionInterface $connection, ?LoggerInterface $logger): void
    {
        if ($logger === null) {
            return;
        }

        $skippedCount = $connection
            ->table('counterparties')
            ->where('counterparties.user_id', $this->userId)
            ->whereNotExists($this->notRecentlyTransacted(...))
            ->whereNotNull('counterparties.merchant_name')
            ->count();

        if ($skippedCount > 0) {
            $logger->warning(
                'CounterpartyGarbageCollectorJob: no app-lock KEK available for an encrypted user in this run — merchant_name/alias-dependent prune skipped for candidate counterparties; they will be re-evaluated on a future run with an available KEK.',
                ['user_id' => $this->userId, 'skipped_count' => $skippedCount],
            );
        }
    }

    // Step 2 nulls the FK on every transaction pointing at a soon-to-be-
    // pruned counterparty (counterparty_id carries no ON DELETE cascade, so
    // this keeps referential integrity through the DELETE without losing
    // history); step 3 deletes the orphans, bounded by user_id and the id list.
    /**
     * @param  list<int>  $orphans
     */
    private function pruneOrphans(ConnectionInterface $connection, array $orphans): void
    {
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
    }
}
