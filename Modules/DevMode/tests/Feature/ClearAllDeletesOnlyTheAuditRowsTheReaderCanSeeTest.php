<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

function clearAllDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'is_developer' => true,
    ]);
}

function clearAllRunBy(User $user, string $marker): void
{
    /** @var AuditWriter $writer */
    $writer = app(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear',
        args: [],
        tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0,
        stdoutExcerpt: $marker,
        errorExcerpt: '',
    ));
}

function clearAllStoredExcerpts(): string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('dev_mode_audit')->pluck('properties')->implode("\n");
}

// The read is scoped to causer_id, so a developer wiping the table destroys
// history they are not allowed to open -- including the row that records their
// own destructive run being answered for by somebody else's audit trail.
it('leaves another developer their rows when this one clears the log', function (): void {
    $alice = clearAllDeveloper('clear-all-alice');
    $bob = clearAllDeveloper('clear-all-bob');

    clearAllRunBy($alice, 'ALICE-ONLY-MARKER');
    clearAllRunBy($bob, 'BOB-ONLY-MARKER');

    Livewire::actingAs($bob)
        ->test(AuditLogPage::class)
        ->call('truncateAll');

    $stored = clearAllStoredExcerpts();

    expect($stored)->toContain('ALICE-ONLY-MARKER')
        ->and($stored)->not->toContain('BOB-ONLY-MARKER');
});

it('takes every row the page would have shown this reader', function (): void {
    $bob = clearAllDeveloper('clear-all-bob-own');

    clearAllRunBy($bob, 'BOB-FIRST-MARKER');
    clearAllRunBy($bob, 'BOB-SECOND-MARKER');

    $page = Livewire::actingAs($bob)->test(AuditLogPage::class);

    expect($page->html())->toContain('BOB-FIRST-MARKER')->toContain('BOB-SECOND-MARKER');

    $page->call('truncateAll');

    expect(clearAllStoredExcerpts())->not->toContain('BOB-')
        ->and(Livewire::actingAs($bob)->test(AuditLogPage::class)->html())->not->toContain('BOB-');
});
