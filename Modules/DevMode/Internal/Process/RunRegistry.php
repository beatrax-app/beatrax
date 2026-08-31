<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\DevMode\Internal\Enums\CommandTier;

// Cached rather than request-scoped so a page refresh mid-command reconnects
// to the live SSE stream. The 24h TTL gives the audit finalize step time to
// copy the stdout/stderr excerpts before the entry expires.
final readonly class RunRegistry
{
    private const string KEY_PREFIX = 'dev_mode.run.';

    // A run record outlives the console that started it, so a reader who
    // comes back the next morning still finds what ran.
    private static function ttlSeconds(): int
    {
        return Duration::Day->seconds();
    }

    public function __construct(
        private CacheRepository $cache,
        private Clock $clock,
    ) {}

    public function store(RunRecord $record): void
    {
        $this->cache->put(self::KEY_PREFIX.$record->runId, $this->serialize($record), self::ttlSeconds());
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

        $this->cache->put(self::KEY_PREFIX.$runId, $this->serialize($updated), self::ttlSeconds());
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

        $this->cache->put(self::KEY_PREFIX.$runId, $this->serialize($updated), self::ttlSeconds());
    }

    // Dates go through ISO 8601 strings and CarbonImmutable::parse() rather
    // than spatie/laravel-data's cast, whose strict default format rejects
    // timezone-offset strings.
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
            'tier' => $record->tier->value,
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
        $tier = $raw['tier'] ?? null;
        $status = $raw['status'] ?? 'running';
        $outPath = $raw['outPath'] ?? '';

        return new RunRecord(
            runId: is_string($runId) ? $runId : '',
            pid: is_int($pid) ? $pid : 0,
            command: is_string($command) ? $command : '',
            args: $args,
            startedAt: $startedAt,
            callerUserId: is_int($callerUserId) ? $callerUserId : 0,
            tier: CommandTier::fromStored($tier),
            status: is_string($status) ? $status : 'running',
            outPath: is_string($outPath) ? $outPath : '',
            exitCode: isset($raw['exitCode']) && is_int($raw['exitCode']) ? $raw['exitCode'] : null,
            finishedAt: $finishedAt,
        );
    }
}
