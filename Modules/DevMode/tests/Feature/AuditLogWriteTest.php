<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Audit\RedactionExcerptCap;
use Modules\DevMode\Internal\Audit\SpatieAuditWriter;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;
use Modules\EmailScan\Models\OAuthSecret;

function auditDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

it('declares the AuditEvent enum taxonomy (locks audit-action strings as enum cases)', function (): void {
    expect(AuditEvent::CommandExecuted->value)->toBe('command_executed');
    expect(AuditEvent::QueueAction->value)->toBe('queue_action');
    expect(AuditEvent::SqlSelect->value)->toBe('sql.select');
});

// Every dev-mode reader narrows dev_mode_audit by log_name, on a plain string
// column with no constraint: a spelling the writer and the readers no longer
// share empties the audit pages while the rows sit in the table.
it('locks the audit log name to the value the rows already on disk carry', function (): void {
    expect(SpatieAuditWriter::LOG_NAME)->toBe('dev_mode');
});

it('surfaces a run the writer recorded on the audit log page', function (): void {
    $user = auditDeveloper('audit-log-name');

    /** @var SpatieAuditWriter $writer */
    $writer = app(AuditWriter::class);

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'db:backup',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::parse('2026-05-24T10:00:00Z'),
        finishedAt: CarbonImmutable::parse('2026-05-24T10:00:05Z'),
        exitCode: 0,
        stdoutExcerpt: 'output ok',
        errorExcerpt: '',
    ));

    Livewire::actingAs($user)->test(AuditLogPage::class)->assertSee('db:backup');
});

it('redacts Authorization: Bearer headers in RedactionExcerptCap', function (): void {
    $cap = new RedactionExcerptCap;
    $out = $cap->apply('Authorization: Bearer abc123def456_xyz');

    expect($out)->toBe('Authorization: Bearer [REDACTED]');
});

it('redacts JWT-shape tokens in RedactionExcerptCap', function (): void {
    $cap = new RedactionExcerptCap;
    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
    $out = $cap->apply('logged in with '.$jwt);

    expect($out)->toContain('[JWT_REDACTED]');
    expect($out)->not->toContain('eyJ');
});

it('truncates content past the 8 KiB cap (default)', function (): void {
    $cap = new RedactionExcerptCap;
    $out = $cap->apply(str_repeat('a', 10_000));

    expect(strlen($out))->toBe(RedactionExcerptCap::DEFAULT_MAX_BYTES);
});

it('binds AuditWriter to SpatieAuditWriter (no Null fallback in container)', function (): void {
    $writer = app(AuditWriter::class);

    expect($writer)->toBeInstanceOf(SpatieAuditWriter::class);
});

it('SpatieAuditWriter writes a row into dev_mode_audit with log_name + description + properties.command', function (): void {
    $user = auditDeveloper('audit-writer');

    /** @var SpatieAuditWriter $writer */
    $writer = app(AuditWriter::class);

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'db:backup',
        args: ['destination' => '/tmp/x.db'],
        tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::parse('2026-05-24T10:00:00Z'),
        finishedAt: CarbonImmutable::parse('2026-05-24T10:00:05Z'),
        exitCode: 0,
        stdoutExcerpt: 'output ok',
        errorExcerpt: '',
    ));

    $row = DB::table('dev_mode_audit')->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->log_name)->toBe('dev_mode');
    expect($row->description)->toBe(AuditEvent::CommandExecuted->value);

    $properties = json_decode((string) $row->properties, true);
    expect($properties)->toBeArray();
    expect($properties['command'] ?? null)->toBe('db:backup');
    expect($properties['tier'] ?? null)->toBe('safe');
    expect($properties['exit_code'] ?? null)->toBe(0);
    expect($properties['stdout_excerpt'] ?? null)->toBe('output ok');
});

it('redacts an oauth_secrets value AND a Bearer header in the audit row (Test 7 — RedactionExcerptCap full scrub-set upgrade)', function (): void {
    $user = auditDeveloper('audit-redact-oauth');
    $this->actingAs($user);

    // Creating the row is the whole setup: the observer busts OAuthScrubSet,
    // so the next compiledPattern() call already carries this literal.
    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'OAUTH_LITERAL_FROM_STDOUT',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    /** @var SpatieAuditWriter $writer */
    $writer = app(AuditWriter::class);

    // Both a Bearer header and the stored secret in one excerpt, so the
    // scrub-set and pattern layers of the cap are exercised together.
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::parse('2026-05-24T10:00:00Z'),
        finishedAt: CarbonImmutable::parse('2026-05-24T10:00:01Z'),
        exitCode: 0,
        stdoutExcerpt: "Authorization: Bearer TopLevelToken\nLeaked: OAUTH_LITERAL_FROM_STDOUT\nDONE",
        errorExcerpt: '',
    ));

    $row = DB::table('dev_mode_audit')->latest('id')->first();
    $properties = json_decode((string) $row->properties, true);

    expect($properties['stdout_excerpt'] ?? '')->toContain('Authorization: Bearer [REDACTED]');
    expect($properties['stdout_excerpt'] ?? '')->toContain('[REDACTED]');
    expect($properties['stdout_excerpt'] ?? '')->not->toContain('OAUTH_LITERAL_FROM_STDOUT');
    expect($properties['stdout_excerpt'] ?? '')->not->toContain('TopLevelToken');
});

it('redacts Bearer header content in the audit row via SpatieAuditWriter + RedactionExcerptCap', function (): void {
    $user = auditDeveloper('audit-redact');

    /** @var SpatieAuditWriter $writer */
    $writer = app(AuditWriter::class);

    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::parse('2026-05-24T10:00:00Z'),
        finishedAt: CarbonImmutable::parse('2026-05-24T10:00:01Z'),
        exitCode: 0,
        stdoutExcerpt: "GET /api\nAuthorization: Bearer XYZsecrettokenvalue\nDONE",
        errorExcerpt: '',
    ));

    $row = DB::table('dev_mode_audit')->latest('id')->first();
    $properties = json_decode((string) $row->properties, true);

    expect($properties['stdout_excerpt'] ?? '')->toContain('Authorization: Bearer [REDACTED]');
    expect($properties['stdout_excerpt'] ?? '')->not->toContain('XYZsecrettokenvalue');
});

it('FinalizeRunAudit reads the per-run tmp file + writes a dev_mode_audit row', function (): void {
    $user = auditDeveloper('finalize-audit');

    $runId = (string) Str::uuid();
    $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');
    @mkdir(dirname($outPath), 0700, true);
    file_put_contents($outPath, "Hello from cache:clear\nAuthorization: Bearer s3cr3ttoken\nDONE\n");

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 1, // sentinel pid; FinalizeRunAudit does not signal anything
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::parse('2026-05-24T10:00:00Z'),
        callerUserId: $user->id,
        tier: CommandTier::Safe,
        status: 'running',
        outPath: $outPath,
    ));

    /** @var FinalizeRunAudit $finalize */
    $finalize = app(FinalizeRunAudit::class);
    ($finalize)($runId, 0, false);

    $row = DB::table('dev_mode_audit')->latest('id')->first();
    expect($row)->not->toBeNull();
    $properties = json_decode((string) $row->properties, true);
    expect($properties['command'] ?? null)->toBe('cache:clear');
    expect($properties['tier'] ?? null)->toBe('safe');
    expect($properties['exit_code'] ?? null)->toBe(0);
    expect($properties['stdout_excerpt'] ?? '')->toContain('Hello from cache:clear');
    expect($properties['stdout_excerpt'] ?? '')->toContain('Authorization: Bearer [REDACTED]');
    expect($properties['stdout_excerpt'] ?? '')->not->toContain('s3cr3ttoken');
});

it('FinalizeRunAudit records a cancelled run with cancelled=true + negative exit code', function (): void {
    $user = auditDeveloper('finalize-cancelled');

    $runId = (string) Str::uuid();
    $outPath = UserDataPathService::appPath('dev_mode/runs/'.$runId.'.out');
    @mkdir(dirname($outPath), 0700, true);
    file_put_contents($outPath, "starting\n");

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 1,
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::parse('2026-05-24T10:00:00Z'),
        callerUserId: $user->id,
        tier: CommandTier::Safe,
        status: 'running',
        outPath: $outPath,
    ));

    /** @var FinalizeRunAudit $finalize */
    $finalize = app(FinalizeRunAudit::class);
    ($finalize)($runId, null, true);

    $row = DB::table('dev_mode_audit')->latest('id')->first();
    $properties = json_decode((string) $row->properties, true);
    expect($properties['exit_code'] ?? null)->toBe(-15);
    expect($properties['args']['__cancelled'] ?? null)->toBeTrue();
});

it('PruneDevAuditCommand requires --older-than to be a positive integer', function (): void {
    auditDeveloper('prune-validation');

    $exit = Artisan::call('beatrax:prune-dev-audit');
    expect($exit)->toBe(1);

    $exit = Artisan::call('beatrax:prune-dev-audit', ['--older-than' => '0']);
    expect($exit)->toBe(1);

    $exit = Artisan::call('beatrax:prune-dev-audit', ['--older-than' => '-3']);
    expect($exit)->toBe(1);

    $exit = Artisan::call('beatrax:prune-dev-audit', ['--older-than' => 'abc']);
    expect($exit)->toBe(1);
});

it('PruneDevAuditCommand deletes only rows older than the given days', function (): void {
    $user = auditDeveloper('prune-delete');
    /** @var SpatieAuditWriter $writer */
    $writer = app(AuditWriter::class);

    foreach (range(1, 3) as $_) {
        $writer->recordCommandRun(new CommandRunAudit(
            command: 'cache:clear', args: [], tier: CommandTier::Safe,
            callerUserId: $user->id,
            startedAt: CarbonImmutable::now(),
            finishedAt: CarbonImmutable::now(),
            exitCode: 0, stdoutExcerpt: 'fresh', errorExcerpt: '',
        ));
    }
    foreach (range(1, 2) as $_) {
        $writer->recordCommandRun(new CommandRunAudit(
            command: 'cache:clear', args: [], tier: CommandTier::Safe,
            callerUserId: $user->id,
            startedAt: CarbonImmutable::now(),
            finishedAt: CarbonImmutable::now(),
            exitCode: 0, stdoutExcerpt: 'stale', errorExcerpt: '',
        ));
    }
    // 30 days back, well clear of the 7-day cutoff used below.
    DB::table('dev_mode_audit')
        ->orderByDesc('id')
        ->limit(2)
        ->update(['created_at' => CarbonImmutable::now()->subDays(30)]);

    expect(DB::table('dev_mode_audit')->count())->toBe(5);

    $exit = Artisan::call('beatrax:prune-dev-audit', ['--older-than' => '7']);
    expect($exit)->toBe(0);

    expect(DB::table('dev_mode_audit')->count())->toBe(3);
});
