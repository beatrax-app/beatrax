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

/**
 * Per-user daily counterparty garbage-collector job. Prunes
 * `counterparties` rows that have become orphans — no transaction
 * within the last 365 days carries the row's FK AND no
 * `merchant_aliases` row anchors the row via
 * `friendly_name = counterparties.merchant_name`. The two-key check
 * is the load-bearing contract: a row preserved by a merchant alias
 * survives a quiet year, and a row preserved by recent activity
 * survives across alias renames.
 *
 * Concurrency contract:
 *  - ShouldBeUniqueUntilProcessing keyed on uniqueId() = "{userId}"
 *    collapses a same-user scheduled tick + on-demand sweep into one
 *    queued job. The lock releases the moment a worker begins
 *    handle(); a long-running GC pass therefore never blocks a
 *    follow-up tick once it has begun executing.
 *  - tries = 3 + backoff = [60, 300, 900] keeps a transient queue or
 *    DB hiccup from final-failing the prune without two retries.
 *  - uniqueVia() returns LockStore::forUniqueJobs() so the
 *    queue-uniqueness lock travels through the shared cache store
 *    every other ShouldBeUnique* job in the project uses.
 *
 * Pruning safety:
 *  - The prune runs in a single DB transaction so a half-applied
 *    purge can never land. Before the DELETE on counterparties, the
 *    transaction NULLs out `transactions.counterparty_id` for every
 *    row that points at a soon-to-be-pruned counterparty. Historical
 *    transactions retain their data — only the FK link is severed —
 *    which is exactly the per-Phase-17 schema decision in the
 *    `add_counterparty_id_to_transactions` migration (FK is from the
 *    user side, NOT cascaded from the counterparty side).
 *  - Cross-user posture: every WHERE clause carries an explicit
 *    `where('user_id', $this->userId)`. The job ONLY touches rows
 *    owned by the user that dispatched it; a per-user scheduled
 *    fan-out is the canonical entry point.
 *
 * Orphan predicate detail:
 *   counterparties.user_id = $userId
 *   AND no transactions row links via counterparty_id within the
 *       last 365 days (SQLite: `created_at >= datetime('now', '-365 days')`)
 *   AND counterparties.merchant_name IS NULL
 *       OR no merchant_aliases row exists with friendly_name =
 *          counterparties.merchant_name AND user_id = $userId.
 *
 * **CRYPT-01 (14.1-15) — KEK-less daemon skip-with-warning:** this job's
 * ONLY dispatch origin is `routes/console.php`'s daily `counterparties.gc`
 * `Schedule::call(...)` per-user fan-out — a real queue worker with no
 * live Session, and therefore NEVER a KEK. `counterparties.merchant_name`
 * is a `SensitiveFieldRegistry`-listed encrypted column; the alias-
 * preservation half of the orphan predicate (`whereColumn(
 * 'merchant_aliases.friendly_name', 'counterparties.merchant_name')`) is a
 * raw SQL string equality between that ciphertext and the always-plaintext
 * `merchant_aliases.friendly_name`. Under encryption the two can never
 * match, so evaluating that branch would make every alias-protected,
 * non-null-merchant_name counterparty wrongly look alias-less and
 * therefore prunable purely because its `merchant_name` is now ciphertext.
 *
 * `merchant_aliases` carries no id/FK link back to `counterparties` (only
 * the string pair), so there is no KEK-independent alias signal to fall
 * back on. `handle()` therefore splits the orphan predicate in two:
 *
 *   - The `merchant_name IS NULL` half is ALWAYS plaintext-safe —
 *     `SensitiveColumnCodec::encryptAttrs()` only encrypts string values
 *     (`is_string($value)` guard), so a NULL `merchant_name` is never
 *     turned into ciphertext by encryption. Rows in this half prune
 *     unconditionally, encrypted or not.
 *   - The `merchant_name IS NOT NULL` half is where the fix branches
 *     three ways:
 *       - Not encrypted: the original raw-SQL `whereColumn` equality is
 *         left untouched — both sides are genuinely plaintext.
 *       - Encrypted AND a KEK is available (never true for this job's
 *         sole daemon origin today, kept symmetric with plan 08's
 *         `DetectRecurringSeriesJob` pattern for a future request-bound
 *         dispatch origin): the raw-SQL equality is NEVER used, because
 *         AEAD ciphertext is non-deterministic (random nonce per
 *         encryption) — even WITH a KEK, `counterparties.merchant_name`
 *         ciphertext can never byte-equal the always-plaintext
 *         `merchant_aliases.friendly_name` at the SQL layer. Instead,
 *         candidates are loaded, `SensitiveColumnCodec::decryptValue()`
 *         decrypts each row's `merchant_name` in PHP (mirrors
 *         `FingerprintStage::detectConflicts()`'s decrypt-before-compare
 *         template), and the decrypted plaintext is compared against the
 *         user's `merchant_aliases.friendly_name` set in PHP.
 *       - Encrypted AND no KEK: the half is SKIPPED entirely — no
 *         candidate in it is ever pruned — and a warning naming the user
 *         and the skipped-row count is logged
 *         (preserve-data-on-uncertainty; NEVER a wrongful prune).
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

        // CRYPT-01 (14.1-15): only gate on KEK-absence when a real
        // Session/AppLockKeyService/EncryptionMigrationService context was
        // resolved (production dispatch — see class docblock). The legacy
        // 1-arg test-call shape leaves all three null, which defaults to
        // "not encrypted" — correct for those tests' always-plaintext
        // fixtures (the raw-SQL branch below runs unchanged).
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
                    // SQLite-portable date arithmetic. The
                    // 365-day window mirrors the long-history
                    // retention promise — a counterparty that
                    // has not been transacted with in a full
                    // year is a strong prune candidate.
                    ->whereRaw("transactions.created_at >= datetime('now', '-365 days')");
            };

            // Step 1a — the `merchant_name IS NULL` half of the orphan
            // predicate. Always plaintext-safe (see class docblock): a
            // NULL column is never turned into ciphertext, so this half
            // prunes unconditionally, encrypted or not.
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

            // Step 1b — the `merchant_name IS NOT NULL` half.
            if (! $isEncrypted) {
                // Not encrypted: both sides of the comparison are
                // genuinely plaintext — the original raw-SQL equality is
                // valid and untouched.
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
                // Encrypted AND a KEK is available: the raw-SQL equality
                // can NEVER match — AEAD ciphertext is non-deterministic
                // (random nonce per encryption), so
                // `counterparties.merchant_name` ciphertext can never
                // byte-equal the plaintext `merchant_aliases.friendly_name`
                // at the SQL layer regardless of KEK availability. Decrypt
                // each candidate's merchant_name in PHP and compare against
                // the user's alias friendly_names in PHP instead.
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

                    // WR-16: decryptValue never throws; on any decrypt failure
                    // (no key for the row's epoch, a rekey/revoked-device epoch
                    // the current keyring no longer covers, or corruption) it
                    // returns the raw CIPHERTEXT with decrypted:false. Comparing
                    // that blob against plaintext alias names would never match,
                    // mis-classify the row as alias-less, and DELETE an
                    // alias-protected counterparty — violating this job's
                    // "NEVER a wrongful prune" contract. Preserve-on-uncertainty:
                    // skip any candidate that did not decrypt.
                    if (! $result['decrypted']) {
                        continue;
                    }

                    if (! in_array($result['value'], $aliasNames, true)) {
                        $orphans[] = $id;
                    }
                }
            } elseif ($logger !== null) {
                // Encrypted with NO KEK — the only shape this job's sole
                // daemon dispatch origin ever produces today. Skip this
                // half entirely (preserve-data-on-uncertainty: NEVER a
                // wrongful prune) and log a warning naming the user and
                // the skipped-row count.
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

            // Step 2 — null out the FK on every transaction that
            // pointed at a soon-to-be-pruned counterparty. The
            // transactions.counterparty_id column is intentionally
            // free of an ON DELETE cascade so a prune can be done
            // safely without losing history; the NULL update keeps
            // referential integrity through the DELETE.
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
