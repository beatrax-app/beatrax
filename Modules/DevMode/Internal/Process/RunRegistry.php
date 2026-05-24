<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\Core\Public\Contracts\Clock;

/**
 * Cache-backed registry of in-flight + recently-completed Dev Console
 * runs (CONTEXT D-16: "`pid` + `run_id` persisted in cache so a page
 * refresh reconnects to the live stream").
 *
 * Each entry is keyed `dev_mode.run.{runId}` and TTLs at 24 hours
 * (RESEARCH Open Q3 recommendation — long enough for the longest
 * SAFE-tier command to complete, short enough to bound cache
 * footprint to one operator-day of activity). The 24h window also
 * gives the audit-pipeline finalize step in 16-04b time to read the
 * tmp file before the cache entry — and only the cache entry —
 * disappears; the tmp file itself stays on disk until its own
 * cleanup (a SAFE-tier `beatrax:prune-dev-runs` is out of scope for
 * this plan).
 *
 * No facade calls — the cache contract is constructor-injected per
 * CLAUDE.md DI-only rule.
 */
final readonly class RunRegistry
{
    /** Cache key prefix for every persisted run. */
    private const KEY_PREFIX = 'dev_mode.run.';

    /** 24 hours in seconds — long enough for any SAFE command. */
    private const TTL_SECONDS = 86_400;

    public function __construct(
        private CacheRepository $cache,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function store(
        string $runId,
        int $pid,
        string $command,
        array $args,
        CarbonInterface $startedAt,
        int $callerUserId,
        string $tier,
        string $outPath,
    ): void {
        $record = new RunRecord(
            runId: $runId,
            pid: $pid,
            command: $command,
            args: $args,
            startedAt: $startedAt,
            callerUserId: $callerUserId,
            tier: $tier,
            status: 'running',
            outPath: $outPath,
        );

        $this->cache->put(self::KEY_PREFIX.$runId, $this->serialize($record), self::TTL_SECONDS);
    }

    public function find(string $runId): ?RunRecord
    {
        $raw = $this->cache->get(self::KEY_PREFIX.$runId);

        if (! is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        return $this->hydrate($raw);
    }

    public function markFinished(string $runId, int $exitCode, ?CarbonInterface $finishedAt = null): void
    {
        $record = $this->find($runId);
        if ($record === null) {
            return;
        }

        $updated = new RunRecord(
            runId: $record->runId,
            pid: $record->pid,
            command: $record->command,
            args: $record->args,
            startedAt: $record->startedAt,
            callerUserId: $record->callerUserId,
            tier: $record->tier,
            status: 'done',
            outPath: $record->outPath,
            exitCode: $exitCode,
            finishedAt: $finishedAt ?? $this->clock->now(),
        );

        $this->cache->put(self::KEY_PREFIX.$runId, $this->serialize($updated), self::TTL_SECONDS);
    }

    public function markCancelled(string $runId): void
    {
        $record = $this->find($runId);
        if ($record === null) {
            return;
        }

        $updated = new RunRecord(
            runId: $record->runId,
            pid: $record->pid,
            command: $record->command,
            args: $record->args,
            startedAt: $record->startedAt,
            callerUserId: $record->callerUserId,
            tier: $record->tier,
            status: 'cancelled',
            outPath: $record->outPath,
            exitCode: $record->exitCode,
            finishedAt: $this->clock->now(),
        );

        $this->cache->put(self::KEY_PREFIX.$runId, $this->serialize($updated), self::TTL_SECONDS);
    }

    /**
     * Convert a RunRecord into a primitive array safe to round-trip
     * through the cache driver. Carbon dates serialise as ISO-8601
     * strings; the {@see hydrate()} step parses them back via
     * CarbonImmutable::parse() to dodge spatie/laravel-data's strict
     * default-format cast (which rejects strings with timezone offset).
     *
     * @return array<string, mixed>
     */
    private function serialize(RunRecord $record): array
    {
        return [
            'runId' => $record->runId,
            'pid' => $record->pid,
            'command' => $record->command,
            'args' => $record->args,
            'startedAt' => $record->startedAt->toIso8601String(),
            'callerUserId' => $record->callerUserId,
            'tier' => $record->tier,
            'status' => $record->status,
            'outPath' => $record->outPath,
            'exitCode' => $record->exitCode,
            'finishedAt' => $record->finishedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function hydrate(array $raw): RunRecord
    {
        $startedAt = is_string($raw['startedAt'] ?? null)
            ? CarbonImmutable::parse($raw['startedAt'])
            : $this->clock->now();

        $finishedAtRaw = $raw['finishedAt'] ?? null;
        $finishedAt = is_string($finishedAtRaw) ? CarbonImmutable::parse($finishedAtRaw) : null;

        $argsRaw = $raw['args'] ?? null;
        /** @var array<string, mixed> $args */
        $args = is_array($argsRaw) ? $argsRaw : [];

        $runId = $raw['runId'] ?? '';
        $pid = $raw['pid'] ?? 0;
        $command = $raw['command'] ?? '';
        $callerUserId = $raw['callerUserId'] ?? 0;
        $tier = $raw['tier'] ?? 'safe';
        $status = $raw['status'] ?? 'running';
        $outPath = $raw['outPath'] ?? '';

        return new RunRecord(
            runId: is_string($runId) ? $runId : '',
            pid: is_int($pid) ? $pid : 0,
            command: is_string($command) ? $command : '',
            args: $args,
            startedAt: $startedAt,
            callerUserId: is_int($callerUserId) ? $callerUserId : 0,
            tier: is_string($tier) ? $tier : 'safe',
            status: is_string($status) ? $status : 'running',
            outPath: is_string($outPath) ? $outPath : '',
            exitCode: isset($raw['exitCode']) && is_int($raw['exitCode']) ? $raw['exitCode'] : null,
            finishedAt: $finishedAt,
        );
    }
}
