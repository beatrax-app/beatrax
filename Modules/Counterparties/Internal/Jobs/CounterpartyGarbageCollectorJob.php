<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\LockStore;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

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

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 3600;
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

        // Only gate on KEK-absence when a real Session/AppLockKeyService/
        // EncryptionMigrationService context was resolved (production
        // dispatch). The legacy 1-arg test-call shape leaves all three
        // null, defaulting to "not encrypted" for always-plaintext fixtures.
        $canDecryptMerchantName = false;
        $isEncrypted = false;
        if ($session !== null && $appLockKeyService !== null && $encryptionMigrationService !== null) {
            $hasKek = $appLockKeyService->release($session) !== null;
            $isEncrypted = $encryptionMigrationService->isEnabled($this->userId);
            $canDecryptMerchantName = $isEncrypted && $hasKek;
        }

        $connection->transaction(function () use ($connection, $isEncrypted, $canDecryptMerchantName, $session, $codec, $logger): void {
            $notRecentlyTransacted = function (Builder $query): void {
                $query
                    ->select(new Expression('1'))
                    ->from('transactions')
                    ->whereColumn('transactions.counterparty_id', 'counterparties.id')
                    ->where('transactions.user_id', $this->userId)
                    // SQLite-portable date arithmetic. The 365-day window
                    // mirrors the long-history retention promise — a
                    // counterparty untouched for a full year is a strong
                    // prune candidate.
                    ->whereRaw("transactions.created_at >= datetime('now', '-365 days')");
            };

            // Step 1a — the `merchant_name IS NULL` half of the orphan
            // predicate. Always plaintext-safe: a NULL column is never
            // turned into ciphertext, so this half prunes unconditionally,
            // encrypted or not.
            /** @var list<int> $orphans */
            $orphans = $connection
                ->table('counterparties')
                ->where('counterparties.user_id', $this->userId)
                ->whereNotExists($notRecentlyTransacted)
                ->whereNull('counterparties.merchant_name')
                ->pluck('counterparties.id')
                ->filter(static fn (mixed $id): bool => is_numeric($id))
                ->map(static fn (int|float|string $id): int => (int) $id)
                ->values()
                ->all();

            // Step 1b — the `merchant_name IS NOT NULL` half branches on
            // encryption state (see the linked doc for the full rationale).
            if (! $isEncrypted) {
                // Not encrypted: both sides of the comparison are
                // genuinely plaintext — the raw-SQL equality is valid.
                $ciphertextBranchOrphans = $connection
                    ->table('counterparties')
                    ->where('counterparties.user_id', $this->userId)
                    ->whereNotExists($notRecentlyTransacted)
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
                    ->values()
                    ->all();

                $orphans = [...$orphans, ...$ciphertextBranchOrphans];
            } elseif ($canDecryptMerchantName && $session !== null && $codec !== null) {
                // Encrypted with a KEK available: AEAD ciphertext can never
                // byte-equal plaintext at the SQL layer, so decrypt each
                // candidate's merchant_name in PHP and compare against the
                // user's alias friendly_names in PHP instead.
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
                    ->whereNotExists($notRecentlyTransacted)
                    ->whereNotNull('counterparties.merchant_name')
                    ->get(['id', 'merchant_name']);

                foreach ($candidates as $candidate) {
                    $rawId = $candidate->id;
                    $id = is_numeric($rawId) ? (int) $rawId : null;
                    if ($id === null || ! is_string($candidate->merchant_name)) {
                        continue;
                    }

                    $result = $codec->decryptValue(
                        'counterparties',
                        'merchant_name',
                        $candidate->merchant_name,
                        $this->userId,
                        $session,
                    );

                    // decryptValue never throws; a decrypt failure (missing
                    // epoch key, revoked-device epoch, or corruption) returns
                    // raw ciphertext with decrypted:false, which would never
                    // match plaintext — skip rather than risk a wrongful prune.
                    if (! $result['decrypted']) {
                        continue;
                    }

                    if (! in_array($result['value'], $aliasNames, true)) {
                        $orphans[] = $id;
                    }
                }
            } elseif ($logger !== null) {
                // Encrypted with no KEK: skip this half entirely
                // (preserve-on-uncertainty — never a wrongful prune) and
                // log a warning naming the user and the skipped-row count.
                $skippedCount = $connection
                    ->table('counterparties')
                    ->where('counterparties.user_id', $this->userId)
                    ->whereNotExists($notRecentlyTransacted)
                    ->whereNotNull('counterparties.merchant_name')
                    ->count();

                if ($skippedCount > 0) {
                    $logger->warning(
                        'CounterpartyGarbageCollectorJob: no app-lock KEK available for an encrypted user in this run — merchant_name/alias-dependent prune skipped for candidate counterparties; they will be re-evaluated on a future run with an available KEK.',
                        ['user_id' => $this->userId, 'skipped_count' => $skippedCount],
                    );
                }
            }

            if ($orphans === []) {
                return;
            }

            // Step 2 — null the FK on every transaction pointing at a
            // soon-to-be-pruned counterparty. counterparty_id carries no
            // ON DELETE cascade, so this keeps referential integrity
            // through the DELETE without losing transaction history.
            $connection
                ->table('transactions')
                ->where('user_id', $this->userId)
                ->whereIn('counterparty_id', $orphans)
                ->update(['counterparty_id' => null]);

            // Step 3 — DELETE the orphans. Bounded by the explicit
            // user_id filter and the collected id list.
            $connection
                ->table('counterparties')
                ->where('user_id', $this->userId)
                ->whereIn('id', $orphans)
                ->delete();
        });
    }
}
