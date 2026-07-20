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
use Spatie\Activitylog\Support\ActivityLogger;

/**
 * Concrete AuditWriter that routes every Dev Console audit row
 * through spatie/laravel-activitylog ^5.0 into the dev_mode_audit
 * table. Bound to the AuditWriter contract in DevModeServiceProvider.
 *
 * Constructor DI on:
 *   - CurrentUser           — read the caller for causedBy().
 *   - Clock                 — present-day default for null finishedAt.
 *   - RedactionExcerptCap   — OAuth scrub-set + Bearer + JWT scrub +
 *                             byte cap on every excerpt that lands in
 *                             the `properties` JSON.
 *   - ActivityLogger        — Spatie's bindable logger. The project's
 *                             DI-only rule forbids the activity()
 *                             global helper.
 *
 * Audit row shape — every recordCommandRun() write:
 *   - log_name    = 'dev_mode' (set in config/activitylog.php).
 *   - description = AuditEvent::CommandExecuted->value (taxonomy is
 *                   enum-locked, never a free-form string).
 *   - causer      = the authenticated developer (or callerUserId).
 *   - properties  = {command, args, tier, exit_code, stdout_excerpt,
 *                    error_excerpt, started_at, finished_at, cancelled}.
 */
final readonly class SpatieAuditWriter implements AuditWriter
{
    /** Custom dev_mode_audit table the package migration creates. */
    private const AUDIT_TABLE = 'dev_mode_audit';

    public function __construct(
        private CurrentUser $currentUser,
        private Clock $clock,
        private RedactionExcerptCap $cap,
        private ActivityLogger $logger,
        private DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function recordCommandRun(
        string $command,
        array $args,
        string $tier,
        int $callerUserId,
        CarbonInterface $startedAt,
        ?CarbonInterface $finishedAt,
        ?int $exitCode,
        string $stdoutExcerpt,
        string $errorExcerpt,
        ?string $runId = null,
    ): void {
        $properties = [
            'command' => $command,
            'args' => $args,
            'tier' => $tier,
            'exit_code' => $exitCode,
            'stdout_excerpt' => $this->cap->apply($stdoutExcerpt),
            'error_excerpt' => $this->cap->apply($errorExcerpt),
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
        ];
        if ($runId !== null && $runId !== '') {
            $properties['run_id'] = $runId;
        }

        $this->dispatch(AuditEvent::CommandExecuted, $callerUserId, $properties);
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

        $connection = $this->db->connection();
        $row = $connection->table(self::AUDIT_TABLE)
            ->where('log_name', 'dev_mode')
            ->where('properties->run_id', $runId)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return false;
        }

        $idRaw = get_object_vars($row)['id'] ?? null;
        if (! is_int($idRaw)) {
            return false;
        }

        $propertiesRaw = get_object_vars($row)['properties'] ?? null;
        $existing = [];
        if (is_string($propertiesRaw)) {
            $decoded = json_decode($propertiesRaw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $existing = $decoded;
            }
        }

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
            ->where('id', $idRaw)
            ->update([
                'properties' => json_encode($existing),
                'updated_at' => $this->clock->now()->toDateTimeString(),
            ]);

        return true;
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
     * Compose the spatie pipeline. `causedBy()` accepts the model OR a
     * raw id; we prefer the model when the caller user equals the
     * authenticated user so the polymorphic causer relation resolves
     * directly, otherwise fall back to the integer id.
     *
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

    /**
     * Returns the User model for the caller. Prefers the authenticated
     * user when ids match; otherwise looks up the user by id. Returns
     * null when no matching user exists so spatie's causerResolver
     * doesn't blow up on a synthetic id (test fixtures, deleted-user
     * audit rows) — a missing causer is preferable to losing the
     * audit row entirely.
     */
    private function resolveCauser(int $callerUserId): ?User
    {
        try {
            $authed = $this->currentUser->user();
            if ($authed->getKey() === $callerUserId) {
                return $authed;
            }
        } catch (NotAuthenticatedException) {
        }

        if ($callerUserId <= 0) {
            return null;
        }

        $found = User::query()->find($callerUserId);

        return $found instanceof User ? $found : null;
    }
}
