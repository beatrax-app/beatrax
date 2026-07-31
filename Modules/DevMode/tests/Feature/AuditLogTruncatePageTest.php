<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

/*
 * /dev/audit "Clear all" + per-row Copy affordances.
 *
 *  - truncateAll() deletes every dev_mode_audit row and resets the
 *    cursor + filter state so the next render shows the empty-state
 *    copy instead of a stale "Older" pager link pointing at a now-
 *    missing id.
 *
 *  - The page renders the "Clear all" button (assertion by data-testid)
 *    and a per-row Copy button (assertion by data-testid) when at least
 *    one audit row exists.
 *
 *  - The Copy button's payload includes the command name, exit code,
 *    and stdout / stderr excerpt — the developer pastes one blob into
 *    a chat / issue / scratchpad without manually re-assembling fields
 *    from the row.
 */

function altpDeveloper(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'is_developer' => true,
    ]);
}

it('truncateAll deletes every dev_mode_audit row', function (): void {
    $user = altpDeveloper('audit-truncate@example.com');

    /** @var AuditWriter $writer */
    $writer = $this->app->make(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear', args: [], tier: 'safe',
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: 'output ok', errorExcerpt: '',
    ));
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'db:restore', args: ['from' => '/tmp/x'], tier: 'destructive',
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 2, stdoutExcerpt: '', errorExcerpt: 'boom',
    ));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('dev_mode_audit')->count())->toBe(2);

    Livewire::actingAs($user)
        ->test(AuditLogPage::class)
        ->call('truncateAll');

    expect($db->connection()->table('dev_mode_audit')->count())->toBe(0);
});

it('truncateAll resets the cursor and filter state', function (): void {
    $user = altpDeveloper('audit-truncate-reset@example.com');

    /** @var AuditWriter $writer */
    $writer = $this->app->make(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear', args: [], tier: 'safe',
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: 'ok', errorExcerpt: '',
    ));

    Livewire::actingAs($user)
        ->test(AuditLogPage::class)
        ->set('tierFilter', 'safe')
        ->set('callerFilter', 'someone')
        ->set('commandFilter', 'cache:clear')
        ->set('before', 999)
        ->call('truncateAll')
        ->assertSet('tierFilter', '')
        ->assertSet('callerFilter', '')
        ->assertSet('commandFilter', '')
        ->assertSet('before', null);
});

it('renders the Clear all button on /dev/audit', function (): void {
    $user = altpDeveloper('audit-clear-button@example.com');

    $response = $this->actingAs($user)->get('/dev/audit');

    $response->assertStatus(200);
    $response->assertSee('data-testid="audit-truncate-button"', false);
    $response->assertSee('Clear all', false);
});

it('renders a per-row Copy button for every audit row with the row payload embedded', function (): void {
    $user = altpDeveloper('audit-copy-button@example.com');

    /** @var AuditWriter $writer */
    $writer = $this->app->make(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear', args: [], tier: 'safe',
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: 'rows deleted: 42', errorExcerpt: '',
    ));

    $response = $this->actingAs($user)->get('/dev/audit');
    $response->assertStatus(200);

    $html = (string) $response->getContent();

    // The Copy button itself renders.
    expect($html)->toContain('data-testid="audit-row-copy-button"');
    expect($html)->toContain('aria-label="Copy row');

    // The serialised clipboard payload embeds the row's fields so the
    // pasted blob carries every captured value without round-tripping
    // through the DB. Use loose containment because the @js helper
    // emits a JSON-escaped string with embedded \n sequences.
    expect($html)->toContain('command: cache:clear');
    expect($html)->toContain('tier: safe');
    expect($html)->toContain('exit_code: 0');
    // stdout excerpt is preserved (@js escapes embedded ":" so look
    // for an unambiguous substring fragment).
    expect($html)->toContain('rows deleted');
});

it('renders the stderr block in the Copy payload when error_excerpt is non-empty', function (): void {
    $user = altpDeveloper('audit-copy-error@example.com');

    /** @var AuditWriter $writer */
    $writer = $this->app->make(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'db:restore', args: ['from' => '/tmp/x'], tier: 'destructive',
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 2, stdoutExcerpt: '', errorExcerpt: 'restore failed: backup missing',
    ));

    $response = $this->actingAs($user)->get('/dev/audit');
    $response->assertStatus(200);

    $html = (string) $response->getContent();
    expect($html)->toContain('stderr');
    expect($html)->toContain('restore failed');
});

it('returns 404 from /dev/audit for a non-developer (defence-in-depth around the truncate surface)', function (): void {
    $user = User::query()->create([
        'username' => 'audit-nondev',
        'password' => 'fixture',
        'period_start_day' => 1,
        'is_developer' => false,
    ]);

    $response = $this->actingAs($user)->get('/dev/audit');

    $response->assertStatus(404);
});
