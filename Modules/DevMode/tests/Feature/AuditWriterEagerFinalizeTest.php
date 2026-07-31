<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

/*
 * SpatieAuditWriter eager-write + finalize-update round-trip.
 *
 * CommandSpawner now writes an "eager" audit row at spawn time with
 * exit_code=null and finished_at=null. FinalizeRunAudit locates that
 * row by `properties.run_id` and updates it in place — the audit
 * table never has two rows for one run.
 *
 * Coverage:
 *
 *   1. recordCommandRun(runId: X) writes a row whose properties
 *      contains run_id=X.
 *   2. finalizeCommandRun(runId: X, ...) finds and updates the same
 *      row (no second row created).
 *   3. finalizeCommandRun returns false when no row with the given
 *      run_id exists.
 *   4. The cancelled flag merges __cancelled=true into properties.args
 *      so the runner page's status mapping treats the row as
 *      cancelled.
 */

function startedAt(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-05-28T10:00:00+00:00');
}

function auditRows(): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('dev_mode_audit')->count();
}

it('recordCommandRun with a runId stores run_id in properties', function (): void {
    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: 'safe',
        callerUserId: 0,
        startedAt: startedAt(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: 'run-eager-1',
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('dev_mode_audit')
        ->where('properties->run_id', 'run-eager-1')
        ->first();

    expect($row)->not->toBeNull();
    $props = json_decode((string) $row->properties, true);
    expect($props['run_id'])->toBe('run-eager-1');
    expect($props['exit_code'])->toBeNull();
    expect($props['finished_at'])->toBeNull();
    expect($props['command'])->toBe('cache:clear');
});

it('finalizeCommandRun updates the existing row in place (no second row created)', function (): void {
    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'config:show',
        args: ['config' => 'app'],
        tier: 'safe',
        callerUserId: 0,
        startedAt: startedAt(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: 'run-finalize-1',
    ));

    expect(auditRows())->toBe(1);

    $updated = $writer->finalizeCommandRun(
        runId: 'run-finalize-1',
        finishedAt: startedAt()->addSeconds(2),
        exitCode: 0,
        stdoutExcerpt: 'app.name = beatrax',
        errorExcerpt: '',
        cancelled: false,
    );

    expect($updated)->toBeTrue();
    expect(auditRows())->toBe(1);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('dev_mode_audit')
        ->where('properties->run_id', 'run-finalize-1')
        ->first();
    $props = json_decode((string) $row->properties, true);

    expect($props['exit_code'])->toBe(0);
    expect($props['finished_at'])->toBe(startedAt()->addSeconds(2)->toIso8601String());
    expect($props['stdout_excerpt'])->toBe('app.name = beatrax');
});

it('finalizeCommandRun returns false when no row matches the runId', function (): void {
    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);

    $updated = $writer->finalizeCommandRun(
        runId: 'never-spawned',
        finishedAt: startedAt(),
        exitCode: 0,
        stdoutExcerpt: 'x',
        errorExcerpt: '',
        cancelled: false,
    );

    expect($updated)->toBeFalse();
    expect(auditRows())->toBe(0);
});

it('finalizeCommandRun with cancelled=true merges __cancelled into args', function (): void {
    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: 'safe',
        callerUserId: 0,
        startedAt: startedAt(),
        finishedAt: null,
        exitCode: null,
        stdoutExcerpt: '',
        errorExcerpt: '',
        runId: 'run-cancel-1',
    ));

    $writer->finalizeCommandRun(
        runId: 'run-cancel-1',
        finishedAt: startedAt()->addSeconds(1),
        exitCode: -15,
        stdoutExcerpt: '',
        errorExcerpt: '',
        cancelled: true,
    );

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('dev_mode_audit')
        ->where('properties->run_id', 'run-cancel-1')
        ->first();
    $props = json_decode((string) $row->properties, true);

    expect($props['args']['__cancelled'] ?? null)->toBe(true);
    expect($props['exit_code'])->toBe(-15);
});
