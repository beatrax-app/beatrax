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

// Every label below is one DoctorCommand's probes actually emit, and the
// spacing is what `%-24s %-8s %s` produces for them. The fixtures used to name
// labels no probe has ("PHP version", "Synchronous mode"), so the parser was
// only ever asked about rows the app does not print — and the real longest
// label, `SQLite synchronous mode`, sat one character from vanishing.
it('parses the last beatrax:doctor audit row into pass/warn/fail rows and renders them on the page', function (): void {
    $user = doctorUser('doctor-parsed');
    $output = <<<'TXT'
beatrax:doctor
-----------------
PHP                      ok       PHP 8.5.7 is at or above 8.5.0.
Composer                 ok       Composer 2.8.4 is installed.
SQLite                   warning  sqlite3: Error: unknown option: --version
Use -help for a list of options. (sqlite3 CLI not on PATH)
Node                     ok       Node v20.18.1 is installed.
SQLite WAL mode          ok       WAL mode is enabled.
SQLite synchronous mode  ok       synchronous = NORMAL.
Backup freshness         critical No verified backups found under the backups directory.
FTS search index         ok       Index is in sync.
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

    expect($html)->toContain('SQLite synchronous mode');
    expect($html)->toContain('Backup freshness');
    expect($html)->toContain('ext-imap');
    expect($html)->toContain('data-probe-status="pass"');
    expect($html)->toContain('data-probe-status="warn"');
    expect($html)->toContain('data-probe-status="fail"');
    expect($html)->toContain('data-probe-status="info"');

    // The half of the sqlite3 message that says the CLI is not on PATH lives
    // on the probe's second line, and the panel used to stop at the first.
    expect($html)->toContain('sqlite3 CLI not on PATH');
});

it('ProbeOutputParser maps severity strings to status buckets', function (): void {
    $output = <<<'TXT'
beatrax:doctor
-----------------
PHP                      ok       PHP 8.5.7 is at or above 8.5.0.
SQLite                   warning  sqlite3 CLI is not on PATH.
Backup freshness         critical No verified backups found.
ext-imap                 info     not loaded
TXT;

    $rows = (new ProbeOutputParser)->parse($output);

    expect($rows)->toHaveCount(4);
    expect($rows[0]['status'])->toBe('pass');
    expect($rows[0]['label'])->toBe('PHP');
    expect($rows[0]['detail'])->toContain('8.5.7');
    expect($rows[1]['status'])->toBe('warn');
    expect($rows[2]['status'])->toBe('fail');
    expect($rows[3]['status'])->toBe('info');
});

// The real output, built the way DoctorCommand builds it, rather than by hand:
// a fixture whose spacing drifts from ROW_FORMAT tests a format nothing emits.
function doctorRow(string $label, string $severity, string $message): string
{
    return sprintf('%-24s %-8s %s', $label, $severity, $message);
}

it('keeps every line of a probe message that spans more than one line', function (): void {
    $output = doctorRow('SQLite', 'warning', "sqlite3: Error: unknown option: --version\nUse -help for a list of options. (sqlite3 CLI not on PATH)");

    $rows = (new ProbeOutputParser)->parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('SQLite');
    expect($rows[0]['detail'])->toContain('unknown option');
    expect($rows[0]['detail'])->toContain('sqlite3 CLI not on PATH');
});

// A continuation line carrying a timestamp and a bracketed level reads enough
// like a row to have become one, inventing a probe nothing ran.
it('does not turn a continuation line into a probe of its own', function (): void {
    $output = doctorRow('Backup freshness', 'warning', 'restore aborted')."\n"
        .'2026-08-28 00:12:44  info  falling back to the bundled library';

    $rows = (new ProbeOutputParser)->parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Backup freshness');
    expect($rows[0]['detail'])->toContain('falling back to the bundled library');
});

// 24 characters is where the old `.{1,24}?` ran out and the row disappeared
// whole. The longest label DoctorCommand ships is 23.
it('parses a row whose label fills or overflows the label column', function (): void {
    foreach ([22, 23, 24, 25, 40] as $length) {
        $label = str_repeat('L', $length);
        $rows = (new ProbeOutputParser)->parse(doctorRow($label, 'ok', 'fine'));

        expect($rows)->toHaveCount(1, "a {$length}-character label should still produce one row");
        expect($rows[0]['label'])->toBe($label);
        expect($rows[0]['detail'])->toBe('fine');
    }
});

it('parses every label DoctorCommand actually ships', function (): void {
    $labels = ['PHP', 'Composer', 'SQLite', 'Node', 'SQLite WAL mode', 'SQLite synchronous mode', 'Backup freshness', 'FTS search index', 'ext-imap'];
    $output = implode("\n", array_map(
        static fn (string $label): string => doctorRow($label, 'ok', 'fine'),
        $labels,
    ));

    $rows = (new ProbeOutputParser)->parse($output);

    expect(array_column($rows, 'label'))->toBe($labels);
});

it('ProbeOutputParser ignores the banner + footer lines (beatrax:doctor / dashes / final summary)', function (): void {
    $output = <<<'TXT'
beatrax:doctor
-----------------
PHP                      ok       PHP 8.5.7 OK.

1 warning(s). Review the output above.
TXT;

    $rows = (new ProbeOutputParser)->parse($output);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('PHP');
    expect($rows[0]['detail'])->toBe('PHP 8.5.7 OK.');
});

it('ProbeOutputParser tolerates empty input', function (): void {
    $rows = (new ProbeOutputParser)->parse('');

    expect($rows)->toBe([]);
});
