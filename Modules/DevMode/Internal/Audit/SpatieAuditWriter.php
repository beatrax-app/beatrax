<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;
use Spatie\Activitylog\Support\ActivityLogger;

final readonly class SpatieAuditWriter implements AuditWriter
{
    private const AUDIT_TABLE = 'dev_mode_audit';

    public function __construct(
        private CurrentUser $currentUser,
        private Clock $clock,
        private RedactionExcerptCap $cap,
        private ActivityLogger $logger,
        private DatabaseManager $db,
    ) {}

    public function recordCommandRun(CommandRunAudit $run): void
    {
        $properties = [
            'command' => $run->command,
            'args' => $run->args,
            'tier' => $run->tier,
            'exit_code' => $run->exitCode,
            'stdout_excerpt' => $this->cap->apply($run->stdoutExcerpt),
            'error_excerpt' => $this->cap->apply($run->errorExcerpt),
            'started_at' => $run->startedAt->toIso8601String(),
            'finished_at' => $run->finishedAt?->toIso8601String(),
        ];
        if ($run->runId !== null && $run->runId !== '') {
            $properties['run_id'] = $run->runId;
        }

        $this->dispatch(AuditEvent::CommandExecuted, $run->callerUserId, $properties);
    }

    public function finalizeCommandRun(
        string $runId,
        CarbonInterface $finishedAt,
        ?int $exitCode,
        string $stdoutExcerpt,
        string $errorExcerpt,
        bool $cancelled,
    ): bool {
        if ($runId === '') {
            return false;
        }

        $located = $this->locateEagerRow($runId);
        if ($located === null) {
            return false;
        }

        $connection = $this->db->connection();
        $existing = $this->decodeExistingProperties($located['properties']);

        $existingArgsRaw = $existing['args'] ?? [];
        /** @var array<string, mixed> $existingArgs */
        $existingArgs = is_array($existingArgsRaw) ? $existingArgsRaw : [];
        if ($cancelled) {
            $existingArgs['__cancelled'] = true;
        }

        $existing['args'] = $existingArgs;
        $existing['exit_code'] = $exitCode;
        $existing['stdout_excerpt'] = $this->cap->apply($stdoutExcerpt);
        $existing['error_excerpt'] = $this->cap->apply($errorExcerpt);
        $existing['finished_at'] = $finishedAt->toIso8601String();

        $connection->table(self::AUDIT_TABLE)
            ->where('id', $located['id'])
            ->update([
                'properties' => json_encode($existing),
                'updated_at' => $this->clock->now()->toDateTimeString(),
            ]);

        return true;
    }

    /**
     * @return array{id: int, properties: mixed}|null
     */
    private function locateEagerRow(string $runId): ?array
    {
        $row = $this->db->connection()->table(self::AUDIT_TABLE)
            ->where('log_name', 'dev_mode')
            ->where('properties->run_id', $runId)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $vars = get_object_vars($row);
        $idRaw = $vars['id'] ?? null;
        if (! is_int($idRaw)) {
            return null;
        }

        return ['id' => $idRaw, 'properties' => $vars['properties'] ?? null];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeExistingProperties(mixed $propertiesRaw): array
    {
        if (! is_string($propertiesRaw)) {
            return [];
        }
        $decoded = json_decode($propertiesRaw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordDestructiveQueueAction(
        string $action,
        array $context,
        int $callerUserId,
    ): void {
        $this->dispatch(AuditEvent::QueueAction, $callerUserId, [
            'action' => $action,
            'context' => $context,
            'recorded_at' => $this->clock->now()->toIso8601String(),
        ]);
    }

    public function recordSelectQuery(
        string $query,
        int $rowcount,
        int $durationMs,
        int $callerUserId,
    ): void {
        $this->dispatch(AuditEvent::SqlSelect, $callerUserId, [
            // The query itself goes through the cap because a user
            // can paste a comment containing a Bearer/JWT literal.
            'query' => $this->cap->apply($query),
            'rowcount' => $rowcount,
            'duration_ms' => $durationMs,
            'recorded_at' => $this->clock->now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function dispatch(AuditEvent $event, int $callerUserId, array $properties): void
    {
        $causer = $this->resolveCauser($callerUserId);

        $logger = $this->logger
            ->useLog('dev_mode')
            ->withProperties($properties);

        if ($causer !== null) {
            $logger = $logger->causedBy($causer);
        }

        $logger->log($event->value);
    }

    // null when the id matches no user: spatie's causerResolver throws on a
    // synthetic one, and a missing causer beats losing the audit row.
    private function resolveCauser(int $callerUserId): ?User
    {
        try {
            $authed = $this->currentUser->user();
            if ($authed->getKey() === $callerUserId) {
                return $authed;
            }
        } catch (NotAuthenticatedException) {
            // A queue worker or console caller has no authenticated user;
            // that is not an error, so fall through to the id lookup.
        }

        if ($callerUserId <= 0) {
            return null;
        }

        $found = User::query()->find($callerUserId);

        return $found instanceof User ? $found : null;
    }
}
