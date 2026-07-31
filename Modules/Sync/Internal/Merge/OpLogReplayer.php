<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Merge\Concerns\AppliesOpLogEntries;
use Modules\Sync\Internal\Merge\Concerns\VerifiesOpLogEntries;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\MergeStrategyInterface;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogReplayer
{
    use AppliesOpLogEntries;
    use VerifiesOpLogEntries;

    // Cascade ops are deterministically re-derived by the replayer on every
    // replay (incremental AND rebuild), never network-received, so they
    // carry no Ed25519 key and no signature. The signature gate allow-lists
    // this device id via isSystemDevice().
    public const string SYSTEM_CASCADE_DEVICE_ID = 'system-cascade';

    private const string SYSTEM_FTS_DEVICE_ID = 'system-fts';

    private const string DEFAULT_STRATEGY = 'lww';

    private DeviceKeySigner $signer;

    private MergeRulesRegistry $rules;

    /** @var array<string, MergeStrategyInterface> */
    private array $strategies;

    private Clock $clock;

    private SensitiveFieldRegistry $sensitiveFields;

    private ?OpLogFieldCrypto $fieldCrypto;

    private ?GdkKeyringService $keyringService;

    private ?SensitiveColumnCodec $columnCodec;

    private ?Session $session;

    /**
     * @param  DatabaseManager  $db  Raw DB access (bypasses Eloquent model events).
     * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
     * @param  MergeRulesRegistry|null  $rules  Config-driven strategy registry (default: new instance).
     * @param  Clock|null  $wallClock  Clock for recorded_at timestamps (default: resolved from container).
     * @param  SearchIndexWriterContract|null  $searchWriter  FTS5 freshness hook.
     *                                                        Null disables FTS updates (used in OpLogRebuilder rebuild path where search is refreshed
     *                                                        in bulk after rebuild, and in tests that do not need FTS).
     * @param  SensitiveFieldRegistry|null  $sensitiveFields  Encryption-scope allow-list (default: new instance — stateless config).
     * @param  OpLogFieldCrypto|null  $fieldCrypto  GDK entry-value AEAD (default: resolved from container; null disables
     *                                              decrypt — a GDK-tagged entry then fails closed to quarantine).
     * @param  GdkKeyringService|null  $keyringService  Per-user epoch keyring (default: resolved from container).
     * @param  SensitiveColumnCodec|null  $columnCodec  Rotation-safe projection-column re-encrypt (default: resolved
     *                                                  from container; null leaves the projection column plaintext).
     * @param  Session|null  $session  Needed to release the app-lock KEK for keyring reads (default: resolved from container).
     */
    public function __construct(
        private DatabaseManager $db,
        private array $deviceKeys = [],
        ?MergeRulesRegistry $rules = null,
        ?Clock $wallClock = null,
        private readonly ?SearchIndexWriterContract $searchWriter = null,
        ?SensitiveFieldRegistry $sensitiveFields = null,
        ?OpLogFieldCrypto $fieldCrypto = null,
        ?GdkKeyringService $keyringService = null,
        ?SensitiveColumnCodec $columnCodec = null,
        ?Session $session = null,
    ) {
        $this->signer = new DeviceKeySigner;
        $this->rules = $rules ?? new MergeRulesRegistry;
        $this->strategies = [
            self::DEFAULT_STRATEGY => new LwwPerFieldStrategy,
            'g_counter' => new GCounterStrategy,
            'or_set' => new OrSetStrategy,
        ];
        // Wall clock: injected (for tests/DI) or resolved from the container.
        // Both honour CarbonImmutable::setTestNow(), so replay timestamps
        // stay deterministic under tests; container resolution is guarded
        // since the replayer is also constructed outside a booted app.
        $this->clock = $wallClock ?? $this->resolveClock();
        $this->sensitiveFields = $sensitiveFields ?? new SensitiveFieldRegistry;
        $this->fieldCrypto = $fieldCrypto ?? $this->resolveFromContainer(OpLogFieldCrypto::class);
        $this->keyringService = $keyringService ?? $this->resolveFromContainer(GdkKeyringService::class);
        $this->columnCodec = $columnCodec ?? $this->resolveFromContainer(SensitiveColumnCodec::class);
        $this->session = $session ?? $this->resolveFromContainer(Session::class);
    }

    private function resolveClock(): Clock
    {
        $container = Container::getInstance();

        if ($container->bound(Clock::class)) {
            return $container->make(Clock::class);
        }

        return new class implements Clock
        {
            public function now(): CarbonImmutable
            {
                return CarbonImmutable::now();
            }
        };
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function resolveFromContainer(string $class): ?object
    {
        $container = Container::getInstance();

        if (! $container->bound($class)) {
            return null;
        }

        $instance = $container->make($class);

        return $instance instanceof $class ? $instance : null;
    }

    // Unconditional json_decode — no raw-string fallback. PHP null input
    // means tombstone/clear sentinel -> return null (write SQL NULL); all
    // other inputs are JSON-encoded by the producer (e.g. '"null"' decodes
    // to the PHP string "null", not PHP null). Public so AlwaysJsonWireContractTest can call it directly.
    public function decodeValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    // LWW/G-Counter bind directly; OR-Set resolves to a list<array{v,tag}>,
    // which a query-builder bind cannot accept ("Array to string
    // conversion"), so any non-scalar, non-null result is JSON-encoded.
    /**
     * @throws \JsonException If a non-scalar value cannot be JSON-encoded; the
     *                        caller catches this and routes the op to quarantine.
     */
    private function encodeColumnValue(mixed $resolved): mixed
    {
        if ($resolved === null || is_scalar($resolved)) {
            return $resolved;
        }

        return json_encode($resolved, JSON_THROW_ON_ERROR);
    }

    // Whether a device id belongs to a deterministically re-derived system
    // op (e.g. the pair-link cascade) that legitimately bypasses the
    // Ed25519 signature gate. Produced ONLY by the replayer itself and
    // reproduced identically on rebuild, so trusted by construction.
    private function isSystemDevice(string $deviceId): bool
    {
        return $deviceId === self::SYSTEM_CASCADE_DEVICE_ID;
    }

    // Re-encrypts a plaintext sensitive-field value for the PROJECTION
    // column write-back (rotation-safe, current-epoch AD) so it stays
    // ciphertext at rest. Pass-through when the field isn't sensitive, the
    // value isn't a string, or the codec/encryption isn't usable for $userId.
    private function reencryptForProjection(string $table, string $field, mixed $value, int $userId): mixed
    {
        if ($this->columnCodec === null || $this->session === null || ! is_string($value)) {
            return $value;
        }

        if (! $this->sensitiveFields->isSensitive($table, $field)) {
            return $value;
        }

        return $this->columnCodec->encryptValue($table, $field, $value, $userId, $this->session);
    }

    // A non-scalar strategy result (OR-Set -> list<array>) is JSON-encoded by
    // the caller before it reaches a SQLite column; the projection column is
    // then re-encrypted under the CURRENT epoch (rotation-safe) — the strategy
    // itself only ever saw plaintext.
    private function resolveStrategy(string $table, string $field): MergeStrategyInterface
    {
        return $this->strategies[$this->rules->strategyFor($table, $field)]
            ?? $this->strategies[self::DEFAULT_STRATEGY];
    }

    // Replays a merged set of op-log entries against the real SQLite schema:
    // filter + verify signatures (rejected -> quarantine only), sort by HLC
    // total order, single-pass resolve per (table, pk, field) via strategy
    // dispatch, then apply in a DB transaction scoped to $userId (see @link).
    /**
     * @param  list<OpLogEntry>  $entries  Entries from all devices (any order).
     * @param  int  $userId  Scope all DB writes to this user.
     */
    public function replay(array $entries, int $userId): void
    {
        $now = $this->clock->now()->toDateTimeString();

        $verified = $this->verifyPersistAndPrepare($entries, $userId, $now);
        $sorted = $this->sortByHlc($verified);
        [$candidatesByField, $tombstones, $creates] = $this->partitionByOpType($sorted);

        // Pair-link cascade deletions and FTS5 freshness ids are collected
        // INSIDE the transaction but consumed AFTER commit — cascade
        // reclassification and FTS5 shadow-table writes cannot run inside the
        // base-table transaction, so the closure fills these by reference.
        /** @var list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}> $pairCascades */
        $pairCascades = [];

        /** @var list<int> $touchedTransactionIds */
        $touchedTransactionIds = [];

        /** @var list<int> $tombstonedTransactionIds */
        $tombstonedTransactionIds = [];

        $this->db->connection()->transaction(
            function () use (
                $candidatesByField,
                $tombstones,
                $creates,
                $userId,
                $now,
                &$pairCascades,
                &$touchedTransactionIds,
                &$tombstonedTransactionIds,
            ): void {
                $this->applyCreates($creates, $tombstones, $userId, $now, $touchedTransactionIds);
                $this->applyFieldMerges(
                    $candidatesByField,
                    $tombstones,
                    $userId,
                    $now,
                    $pairCascades,
                    $touchedTransactionIds,
                    $tombstonedTransactionIds,
                );
                $this->applyBareTombstones(
                    $candidatesByField,
                    $tombstones,
                    $userId,
                    $pairCascades,
                    $tombstonedTransactionIds,
                );
            },
        );

        $this->applyPairCascades($pairCascades, $userId, $now, $touchedTransactionIds);
        $this->refreshSearchIndex($touchedTransactionIds, $tombstonedTransactionIds, $userId, $now);
    }

    private function quarantine(OpLogEntry $entry, QuarantineReason $reason, string $now): void
    {
        try {
            $this->db->connection()->table('op_log_quarantine')->insert([
                'user_id' => $entry->userId,
                'table_name' => $entry->table,
                'pk' => (string) $entry->pk,
                'device_id' => $entry->deviceId,
                'reason' => $reason->value,
                'hlc_l' => $entry->hlcL,
                'hlc_c' => $entry->hlcC,
                'raw_value' => $entry->value,
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Never propagate a quarantine write failure — replay must
            // continue regardless.
        }
    }
}
