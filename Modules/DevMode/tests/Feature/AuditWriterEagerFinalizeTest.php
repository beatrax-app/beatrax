<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

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
        tier: CommandTier::Safe,
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
        tier: CommandTier::Safe,
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
        stdoutExcerpt: 'app.name = Beatrax',
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
    expect($props['stdout_excerpt'])->toBe('app.name = Beatrax');
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
        tier: CommandTier::Safe,
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
