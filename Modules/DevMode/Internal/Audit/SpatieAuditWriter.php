<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Carbon\CarbonInterface;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Spatie\Activitylog\Support\ActivityLogger;

/**
 * Concrete AuditWriter that routes every Dev Console audit row through
 * spatie/laravel-activitylog ^5.0 into the renamed `dev_mode_audit`
 * table (CONTEXT D-23 / D-24). Replaces `NullAuditWriter` (16-03's
 * placeholder binding) at the DevModeServiceProvider::register() layer.
 *
 * Constructor DI on:
 *   - CurrentUser           — read the caller for `causedBy()`.
 *   - Clock                 — present-day default for any null finishedAt.
 *   - RedactionExcerptCap   — Bearer + JWT scrub + 8 KiB cap on every
 *                             excerpt that lands in the `properties` JSON.
 *   - ActivityLogger        — spatie's bindable logger. Per Laravel
 *                             DI-only rule (CLAUDE.md), we never call
 *                             the `activity()` global helper.
 *
 * Audit row shape (D-24) — every `recordCommandRun` write:
 *   - log_name    = 'dev_mode' (set by config('activitylog.default_log_name'))
 *   - description = AuditEvent::CommandExecuted->value (I-5 fix —
 *                   taxonomy is enum-locked, never a free-form string)
 *   - causer      = the authenticated developer (or callerUserId)
 *   - properties  = {command, args, tier, exit_code, stdout_excerpt,
 *                    error_excerpt, started_at, finished_at, cancelled}
 *
 * 16-05 upgrade contract for RedactionExcerptCap: 16-05 adds the full
 * OAuthScrubSet to the cap's constructor; this writer is unchanged
 * across the upgrade because the constructor DI chain resolves through
 * the container.
 */
final readonly class SpatieAuditWriter implements AuditWriter
{
    public function __construct(
        private CurrentUser $currentUser,
        private Clock $clock,
        private RedactionExcerptCap $cap,
        private ActivityLogger $logger,
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
    ): void {
        $this->dispatch(AuditEvent::CommandExecuted, $callerUserId, [
            'command' => $command,
            'args' => $args,
            'tier' => $tier,
            'exit_code' => $exitCode,
            'stdout_excerpt' => $this->cap->apply($stdoutExcerpt),
            'error_excerpt' => $this->cap->apply($errorExcerpt),
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
        ]);
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
     * Returns the User model when the authenticated user matches the
     * callerUserId, the int id otherwise (so a background job that
     * passes a foreign user id still writes a row with a usable
     * `causer_id`).
     */
    private function resolveCauser(int $callerUserId): User|int|null
    {
        try {
            $user = $this->currentUser->user();
        } catch (NotAuthenticatedException) {
            return $callerUserId > 0 ? $callerUserId : null;
        }

        if ($user->getKey() === $callerUserId) {
            return $user;
        }

        return $callerUserId > 0 ? $callerUserId : null;
    }
}
