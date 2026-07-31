<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\Core\Public\Contracts\Clock;

// The pid + run_id pair is persisted in cache (keyed dev_mode.run.{runId})
// so a page refresh during a running command reconnects to the live SSE
// stream. The 24h TTL lets the audit-pipeline finalize step copy
// stdout/stderr excerpts before the entry disappears.
final readonly class RunRegistry
{
    private const KEY_PREFIX = 'dev_mode.run.';

    private const TTL_SECONDS = 86_400;

    public function __construct(
        private CacheRepository $cache,
        private Clock $clock,
    ) {}

    // Takes the assembled RunRecord whole rather than a long positional
    // parameter list; CommandSpawner builds the record (status 'running')
    // and the cache round-trips it via serialize()/hydrate().
    public function store(RunRecord $record): void
    {
        $this->cache->put(self::KEY_PREFIX.$record->runId, $this->serialize($record), self::TTL_SECONDS);
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

    // Carbon dates serialise as ISO 8601 strings; hydrate() parses them
    // back via CarbonImmutable::parse() to dodge spatie/laravel-data's
    // strict default-format cast (which rejects timezone-offset strings).
    /**
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
