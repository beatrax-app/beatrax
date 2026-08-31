<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Audit\RedactionExcerptCap;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

function recoveryAuditUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

/**
 * @return list<string>
 */
function tenFreshRecoveryCodes(): array
{
    $generator = new RecoveryCodeGenerator;
    $codes = [];
    while (count($codes) < 10) {
        $code = $generator->generate();
        if (! in_array($code, $codes, true)) {
            $codes[] = $code;
        }
    }

    return $codes;
}

function recoveryCommandStdout(string $username, array $codes): string
{
    return "Regenerated {$username} recovery codes. Record them now — they will not be shown again:\n".implode("\n", $codes)."\n";
}

// beatrax:regenerate-recovery-codes is a registered destructive Dev Console
// command and its stdout IS the sheet of ten live credentials.
it('redacts every recovery code out of a stored command excerpt', function (): void {
    $codes = tenFreshRecoveryCodes();
    $cap = new RedactionExcerptCap;

    $out = $cap->apply(recoveryCommandStdout('alice', $codes));

    foreach ($codes as $code) {
        expect($out)->not->toContain($code);
    }
    expect($out)->toContain('[REDACTED]');
    expect($out)->toContain('Regenerated alice recovery codes');
});

it('leaves ordinary hyphenated output alone', function (): void {
    $cap = new RedactionExcerptCap;

    $out = $cap->apply('batch-cancel-001 finished at 2026-08-28 with uuid 550e8400-e29b-41d4-a716-446655440000');

    expect($out)->toBe('batch-cancel-001 finished at 2026-08-28 with uuid 550e8400-e29b-41d4-a716-446655440000');
});

it('never lands a live recovery code in the dev_mode_audit row', function (): void {
    $user = recoveryAuditUser('recovery-audit-writer');
    $codes = tenFreshRecoveryCodes();

    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'beatrax:regenerate-recovery-codes',
        args: ['username' => 'alice'],
        tier: CommandTier::Destructive,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0,
        stdoutExcerpt: recoveryCommandStdout('alice', $codes),
        errorExcerpt: '',
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stored = (string) $db->connection()->table('dev_mode_audit')->value('properties');

    foreach ($codes as $code) {
        expect($stored)->not->toContain($code);
    }
});

// The Copy button on this page hands the whole excerpt to the clipboard, so a
// row another developer wrote is a row this reader can walk off with.
it('shows a developer only their own audit rows', function (): void {
    $alice = recoveryAuditUser('audit-scope-alice');
    $bob = recoveryAuditUser('audit-scope-bob');

    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'beatrax:regenerate-recovery-codes',
        args: ['username' => 'alice'],
        tier: CommandTier::Destructive,
        callerUserId: $alice->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0,
        stdoutExcerpt: 'ALICE-ONLY-MARKER',
        errorExcerpt: '',
    ));
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $bob->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0,
        stdoutExcerpt: 'BOB-ONLY-MARKER',
        errorExcerpt: '',
    ));

    $bobsPage = Livewire::actingAs($bob)->test(AuditLogPage::class)->html();

    expect($bobsPage)->toContain('BOB-ONLY-MARKER');
    expect($bobsPage)->not->toContain('ALICE-ONLY-MARKER');
    expect($bobsPage)->not->toContain('beatrax:regenerate-recovery-codes');
});
