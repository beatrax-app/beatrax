<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Enums;

/**
 * Canonical taxonomy for every event written into the `dev_mode_audit`
 * table by SpatieAuditWriter (per I-5 fix — no free-form audit-action
 * strings).
 *
 * Every `recordCommandRun`, `recordDestructiveQueueAction`, and
 * `recordSelectQuery` writer call passes one of these cases (via
 * `AuditEvent::Foo->value`) as the spatie/laravel-activitylog
 * `->log(...)` description so the row's `description` column is a
 * filterable known string. Free-form strings are forbidden — the
 * writer's PHPDoc + the SpatieAuditWriter implementation always
 * dispatches through this enum.
 *
 * 16-06 (queue inspector) and 16-07 (SQL panel) extend this enum with
 * additional cases. The rule is: NEVER pass a free-form description
 * to the writer; always add a new case here first.
 */
enum AuditEvent: string
{
    /** SAFE-tier or DESTRUCTIVE-tier artisan command completed. */
    case CommandExecuted = 'command_executed';

    /** Cancellation (SIGTERM → SIGKILL) recorded as a distinct event. */
    case CommandCancelled = 'command_cancelled';

    /** Bulk queue action (retry/flush/kill) — reused by 16-06. */
    case QueueAction = 'queue_action';

    /** SELECT-only SQL query executed through 16-07's Read-only panel. */
    case SqlSelect = 'sql.select';
}
