<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Internal\Http\Livewire\SqlPanelPage;

function sqlPanelUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('returns 200 GET /dev/sql for a developer with the page header + textarea + Run button + schema viewer', function (): void {
    $user = sqlPanelUser('sql-dev');

    $response = $this->actingAs($user)->get('/dev/sql');

    $response->assertOk();
    $response->assertSee('SQL', escape: false);
    $response->assertSee('data-testid="sql-textarea"', escape: false);
    $response->assertSee('data-testid="sql-run-button"', escape: false);
    $response->assertSee('data-testid="schema-viewer"', escape: false);
});

it('shows the Advanced-toggle banner when Advanced is OFF (default)', function (): void {
    $user = sqlPanelUser('sql-gated');

    $response = $this->actingAs($user)->get('/dev/sql');

    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->toContain('Enable Advanced');
});

it('returns 404 from /dev/sql for a non-developer', function (): void {
    sqlPanelUser('sql-seed');
    $user = sqlPanelUser('sql-nondev', false);

    $response = $this->actingAs($user)->get('/dev/sql');

    $response->assertNotFound();
});

it('writes a dev_mode_audit row with action sql.select + query / rowcount / duration_ms when a SELECT is run with Advanced ON', function (): void {
    $user = sqlPanelUser('sql-audit');
    session(['dev_mode.advanced' => true]);

    Livewire::actingAs($user)
        ->test(SqlPanelPage::class)
        ->set('sqlInput', 'SELECT id, username FROM users')
        ->call('run');

    $row = DB::table('dev_mode_audit')->latest('id')->first();
    expect($row)->not->toBeNull();
    $properties = json_decode((string) $row->properties, true);
    expect($properties)->toHaveKey('query');
    expect($properties['query'])->toBe('SELECT id, username FROM users');
    expect($properties)->toHaveKey('rowcount');
    expect($properties['rowcount'])->toBeInt();
    expect($properties)->toHaveKey('duration_ms');
    expect($row->description)->toBe(AuditEvent::SqlSelect->value);
});

it('blocks a SELECT submission and surfaces a parse-rejection error when the input is not a SELECT', function (): void {
    $user = sqlPanelUser('sql-reject');
    session(['dev_mode.advanced' => true]);

    $component = Livewire::actingAs($user)
        ->test(SqlPanelPage::class)
        ->set('sqlInput', 'INSERT INTO users (username) VALUES (\'x\')')
        ->call('run');

    $errorMessage = (string) $component->get('errorMessage');
    expect($errorMessage)->toContain('Only SELECT statements are allowed');
    expect(DB::table('dev_mode_audit')->count())->toBe(0);
});

it('renders schema viewer entries for the users + dev_mode_audit tables', function (): void {
    $user = sqlPanelUser('sql-schema');

    $response = $this->actingAs($user)->get('/dev/sql');

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('users');
    expect($html)->toContain('dev_mode_audit');
});

it('Browse-table runs a SELECT * FROM <table> LIMIT 100 through the SQL pipeline AND writes an audit row', function (): void {
    $user = sqlPanelUser('sql-browse');
    session(['dev_mode.advanced' => true]);

    Livewire::actingAs($user)
        ->test(SqlPanelPage::class)
        ->call('browseTable', 'users');

    $row = DB::table('dev_mode_audit')->latest('id')->first();
    expect($row)->not->toBeNull();
    $properties = json_decode((string) $row->properties, true);
    expect($properties['query'])->toContain('SELECT * FROM');
    expect($properties['query'])->toContain('users');
    expect($properties['query'])->toContain('LIMIT 100');
    expect($row->description)->toBe(AuditEvent::SqlSelect->value);
});
