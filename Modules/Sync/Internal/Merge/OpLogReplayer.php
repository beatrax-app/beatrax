<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogReplayer
{
    // Cascade ops are deterministically re-derived by the replayer on every
    // replay (incremental AND rebuild), never network-received, so they
    // carry no Ed25519 key and no signature. The signature gate allow-lists
    // this device id via the verifier's system-device check.
    public const string SYSTEM_CASCADE_DEVICE_ID = 'system-cascade';

    private DatabaseManager $db;

    private Clock $clock;

    private OpLogValueProjector $projector;

    private OpLogEntryVerifier $verifier;

    private OpLogEntryApplier $applier;

    private SearchIndexRefresher $searchRefresher;

    private TransferPairCascade $pairCascade;

    /**
     * @param  DatabaseManager  $db  Raw DB access (bypasses Eloquent model events).
     * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
     * @param  MergeRulesRegistry|null  $rules  Config-driven strategy registry (default: new instance).
     * @param  Clock|null  $wallClock  Clock for recorded_at timestamps (default: resolved from container).
     * @param  SearchIndexWriterContract|null  $searchWriter  FTS5 freshness hook.
     *                                                        Null disables FTS updates (used in OpLogRebuilder rebuild path where search is refreshed
     *                                                        in bulk after rebuild, and in tests that do not need FTS).
     * @param  OpLogReplayCrypto|null  $crypto  Bundled encryption-scope allow-list, GDK entry AEAD, per-user epoch
     *                                          keyring, projection-column codec and app-lock session. Each field (and
     *                                          the whole context) defaults to container resolution; a null GDK crypto
     *                                          fails a GDK-tagged entry closed to quarantine.
     */
    public function __construct(
        DatabaseManager $db,
        array $deviceKeys = [],
        ?MergeRulesRegistry $rules = null,
        ?Clock $wallClock = null,
        ?SearchIndexWriterContract $searchWriter = null,
        ?OpLogReplayCrypto $crypto = null,
    ) {
        $this->db = $db;
        $rules ??= new MergeRulesRegistry;
        $crypto ??= new OpLogReplayCrypto;
        $sensitiveFields = $crypto->sensitiveFields ?? new SensitiveFieldRegistry;

        // Wall clock: injected (for tests/DI) or resolved from the container.
        // Both honour CarbonImmutable::setTestNow(), so replay timestamps
        // stay deterministic under tests; container resolution is guarded
        // since the replayer is also constructed outside a booted app.
        $this->clock = $wallClock ?? $this->resolveClock();
        $fieldCrypto = $crypto->fieldCrypto ?? $this->resolveFromContainer(OpLogFieldCrypto::class);
        $keyringService = $crypto->keyringService ?? $this->resolveFromContainer(GdkKeyringService::class);
        $columnCodec = $crypto->columnCodec ?? $this->resolveFromContainer(SensitiveColumnCodec::class);
        $session = $crypto->session ?? $this->resolveFromContainer(Session::class);

        // The verify half owns the device-key map and signature gate; the
        // apply half owns the merge strategies; both share one quarantine sink.
        // Row ownership, deferred self-references and the search index are
        // their own collaborators — each a question the merge needs answered.
        $quarantine = new OpLogQuarantine($db);
        $this->projector = new OpLogValueProjector($rules, $sensitiveFields, $columnCodec, $session);
        $this->verifier = new OpLogEntryVerifier(
            $db,
            $rules,
            $sensitiveFields,
            $deviceKeys,
            new DeviceKeySigner,
            $fieldCrypto,
            $keyringService,
            $session,
            $quarantine,
        );
        $ownership = new RowOwnership($db);
        $this->pairCascade = new TransferPairCascade($db);
        $this->applier = new OpLogEntryApplier(
            $db,
            $rules,
            $this->projector,
            $quarantine,
            $ownership,
            new SelfReferenceDeferral($db, $ownership),
            $this->pairCascade,
        );
        $this->searchRefresher = new SearchIndexRefresher($db, $searchWriter);
    }

    // Creates arrive grouped by table in HLC order, which says nothing about
    // referential order: a transaction could be written before the account it
    // points at, and SQLite rejects that outright.
    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $creates
     * @return array<string, array<int|string, array<string, list<OpLogEntry>>>>
     */
    private function parentsFirst(array $creates): array
    {
        $order = $this->resolveFromContainer(CoveredTableOrder::class);

        if ($order === null) {
            return $creates;
        }

        $ordered = [];

        foreach ($order->insertionOrder() as $table) {
            if (isset($creates[$table])) {
                $ordered[$table] = $creates[$table];
            }
        }

        // Anything the order does not know about keeps its original position
        // rather than being silently dropped.
        foreach ($creates as $table => $rows) {
            if (! isset($ordered[$table])) {
                $ordered[$table] = $rows;
            }
        }

        return $ordered;
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

        $verified = $this->verifier->verifyPersistAndPrepare($entries, $userId, $now);
        $sorted = $this->applier->sortByHlc($verified);
        [$candidatesByField, $tombstones, $creates] = $this->applier->partitionByOpType($sorted);

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
                $this->applier->applyCreates($this->parentsFirst($creates), $tombstones, $userId, $now, $touchedTransactionIds);
                $this->applier->applyFieldMerges(
                    $candidatesByField,
                    $tombstones,
                    $userId,
                    $now,
                    $pairCascades,
                    $touchedTransactionIds,
                    $tombstonedTransactionIds,
                );
                $this->applier->applyBareTombstones(
                    $candidatesByField,
                    $tombstones,
                    $userId,
                    $pairCascades,
                    $tombstonedTransactionIds,
                    $creates,
                );
            },
        );

        $this->pairCascade->apply($pairCascades, $userId, $now, $touchedTransactionIds);
        $this->searchRefresher->refresh($touchedTransactionIds, $tombstonedTransactionIds, $userId, $now);
    }
}
