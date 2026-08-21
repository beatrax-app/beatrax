<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Doctor\ProbeOutputParser;

function doctorUser(string $username, bool $isDeveloper = true): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('renders GET /dev/doctor for an authenticated developer with the page header + Re-run button + empty card', function (): void {
    $user = doctorUser('doctor-dev');

    $response = $this->actingAs($user)->get('/dev/doctor');

    $response->assertOk();
    $response->assertSee('Doctor', escape: false);
    $response->assertSee('Re-run', escape: false);
    $response->assertSee('No probe output captured yet.', escape: false);
});

it('returns 404 from GET /dev/doctor for an authenticated non-developer', function (): void {
    doctorUser('doctor-seed');
    $user = doctorUser('doctor-nondev', false);

    $response = $this->actingAs($user)->get('/dev/doctor');

    $response->assertNotFound();
});

it('parses the last beatrax:doctor audit row into pass/warn/fail rows and renders them on the page', function (): void {
    $user = doctorUser('doctor-parsed');
    $output = <<<'TXT'
beatrax:doctor
-----------------
PHP version              ok       PHP 8.5.0 is at or above 8.5.0.
Composer version         ok       Composer 2.8.4 is installed.
SQLite CLI version       warning  sqlite3 CLI is not on PATH.
Node version             ok       Node v20.18.1 is installed.
SQLite WAL mode          ok       WAL mode is enabled.
Synchronous mode         ok       synchronous = NORMAL.
Backup freshness         critical No verified backups found under the backups directory.
ext-imap                 info     not loaded (Beatrax uses webklex/php-imap regardless)

1 warning(s). Review the output above.
TXT;
    DB::table('dev_mode_audit')->insert([
        'log_name' => 'dev_mode',
        'description' => 'command_executed',
        'subject_type' => null,
        'subject_id' => null,
        'causer_type' => User::class,
        'causer_id' => $user->id,
        'event' => null,
        'attribute_changes' => null,
        'properties' => json_encode([
            'command' => 'beatrax:doctor',
            'args' => [],
            'tier' => 'safe',
            'exit_code' => 1,
            'stdout_excerpt' => $output,
            'error_excerpt' => '',
        ], JSON_THROW_ON_ERROR),
        'created_at' => Carbon::now()->toDateTimeString(),
        'updated_at' => Carbon::now()->toDateTimeString(),
    ]);

    $response = $this->actingAs($user)->get('/dev/doctor');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('PHP version');
    expect($html)->toContain('Backup freshness');
    expect($html)->toContain('ext-imap');
    expect($html)->toContain('data-probe-status="pass"');
    expect($html)->toContain('data-probe-status="warn"');
    expect($html)->toContain('data-probe-status="fail"');
    expect($html)->toContain('data-probe-status="info"');
});

it('ProbeOutputParser maps severity strings to status buckets', function (): void {
    $output = <<<'TXT'
beatrax:doctor
-----------------
PHP version              ok       PHP 8.5.0 is at or above 8.5.0.
SQLite CLI version       warning  sqlite3 CLI is not on PATH.
Backup freshness         critical No verified backups found.
ext-imap                 info     not loaded
TXT;

    $rows = (new ProbeOutputParser)->parse($output);

    expect($rows)->toHaveCount(4);
    expect($rows[0]['status'])->toBe('pass');
    expect($rows[0]['label'])->toBe('PHP version');
    expect($rows[0]['detail'])->toContain('8.5.0');
    expect($rows[1]['status'])->toBe('warn');
    expect($rows[2]['status'])->toBe('fail');
    expect($rows[3]['status'])->toBe('info');
});

it('ProbeOutputParser ignores the banner + footer lines (beatrax:doctor / dashes / final summary)', function (): void {
    $output = <<<'TXT'
beatrax:doctor
-----------------
PHP version              ok       PHP 8.5.0 OK.

1 warning(s). Review the output above.
TXT;

    $rows = (new ProbeOutputParser)->parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('PHP version');
});

it('ProbeOutputParser tolerates empty input', function (): void {
    $rows = (new ProbeOutputParser)->parse('');

    expect($rows)->toBe([]);
});
