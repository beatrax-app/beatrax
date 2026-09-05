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

function altpDeveloper(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'is_developer' => true,
    ]);
}

it('truncateAll deletes the calling developer\'s dev_mode_audit rows', function (): void {
    $user = altpDeveloper('audit-truncate@example.com');

    /** @var AuditWriter $writer */
    $writer = $this->app->make(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'cache:clear', args: [], tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: 'output ok', errorExcerpt: '',
    ));
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'db:restore', args: ['path' => '/tmp/x'], tier: CommandTier::Destructive,
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
        command: 'cache:clear', args: [], tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: 'ok', errorExcerpt: '',
    ));

    Livewire::actingAs($user)
        ->test(AuditLogPage::class)
        ->set('tierFilter', 'safe')
        ->set('commandFilter', 'cache:clear')
        ->set('before', 999)
        ->call('truncateAll')
        ->assertSet('tierFilter', '')
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
        command: 'cache:clear', args: [], tier: CommandTier::Safe,
        callerUserId: $user->id,
        startedAt: CarbonImmutable::now(),
        finishedAt: CarbonImmutable::now(),
        exitCode: 0, stdoutExcerpt: 'rows deleted: 42', errorExcerpt: '',
    ));

    $response = $this->actingAs($user)->get('/dev/audit');
    $response->assertStatus(200);

    $html = (string) $response->getContent();

    expect($html)->toContain('data-testid="audit-row-copy-button"');
    expect($html)->toContain('aria-label="Copy row');

    // Loose containment throughout: @js emits a JSON-escaped string, so the
    // payload arrives with \n sequences and escaped colons.
    expect($html)->toContain('command: cache:clear');
    expect($html)->toContain('tier: safe');
    expect($html)->toContain('exit_code: 0');
    expect($html)->toContain('rows deleted');
});

it('renders the stderr block in the Copy payload when error_excerpt is non-empty', function (): void {
    $user = altpDeveloper('audit-copy-error@example.com');

    /** @var AuditWriter $writer */
    $writer = $this->app->make(AuditWriter::class);
    $writer->recordCommandRun(new CommandRunAudit(
        command: 'db:restore', args: ['path' => '/tmp/x'], tier: CommandTier::Destructive,
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
