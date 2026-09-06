<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Enums\SyncSessionStatus;

uses(RefreshDatabase::class);

// A vocabulary declared in a schema comment and nowhere else is a claim nothing
// re-reads. `connecting` and `handshaking` were listed as values the column
// could hold, two readers branched on them by literal, and no writer had ever
// written either — so a state machine with five states shipped with three.
// Held in both directions here: a declared value with no writer is as much a
// defect as a written value nothing declares.

const SESSION_STATUS_SCHEMA_FILE = 'Modules/Sync/Database/Migrations/2026_06_15_000006_create_sync_sessions_table.php';

/**
 * @return array<string, string> path => source of every Sync production file that names the table
 */
function sessionStatusWriterSources(): array
{
    $sources = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules/Sync')));

    foreach ($walk as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
            continue;
        }

        $source = (string) file_get_contents($path);
        if (str_contains($source, 'sync_sessions')) {
            $sources[$path] = $source;
        }
    }

    return $sources;
}

/**
 * @return list<string> the vocabulary the create-table migration declares for the column
 */
function declaredSessionStatuses(): array
{
    $source = (string) file_get_contents(base_path(SESSION_STATUS_SCHEMA_FILE));
    $declared = PatternScan::first('/\n\s*\/\/\s*((?:[a-z_]+ \| )+[a-z_]+)\.\s*\n/', $source);

    expect($declared)->not->toBeEmpty(SESSION_STATUS_SCHEMA_FILE.' no longer declares the column vocabulary as a `a | b | c.` comment line');

    $values = array_map('trim', PatternScan::split('/\|/', $declared[1]));
    sort($values);

    return array_values($values);
}

/**
 * The enum arm and the bare-literal arm are both read, so this reports what the
 * tree writes today rather than what it would write once converted — which is
 * what lets the rule name the gap on a tree that has no enum yet.
 *
 * @return list<string> every value Sync production code puts into the column
 */
function writtenSessionStatuses(): array
{
    $written = [];

    foreach (sessionStatusWriterSources() as $source) {
        foreach (PatternScan::sets('/SyncSessionStatus::([A-Z][A-Za-z]*)\b/', $source) as $match) {
            foreach (SyncSessionStatus::cases() as $case) {
                if ($case->name === $match[1]) {
                    $written[$case->value] = true;
                }
            }
        }

        foreach (PatternScan::sets('/(?:\'status\'\s*=>|\bstatus:)\s*\'([a-z_]+)\'/', $source) as $match) {
            $written[$match[1]] = true;
        }
    }

    ksort($written);

    return array_keys($written);
}

/**
 * @return list<list<string>> the vocabulary each installed trigger actually enforces
 */
function triggerEnforcedSessionStatuses(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $enforced = [];

    foreach ($db->connection()->select("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'sync_sessions'") as $trigger) {
        $sql = is_string($trigger->sql ?? null) ? $trigger->sql : '';
        $list = PatternScan::first('/NOT IN \(([^)]*)\)/i', $sql);

        if ($list === []) {
            continue;
        }

        $values = array_map(
            static fn (string $value): string => trim(trim($value), "'"),
            PatternScan::split('/,/', $list[1]),
        );
        sort($values);
        $enforced[] = array_values($values);
    }

    return $enforced;
}

// If the walk or the patterns break, everything reads as unwritten and the rule
// below inverts into noise rather than going quiet. This is the half that fails
// first when that happens.
it('scans a corpus that can still see the writer, so a silent scan cannot pass this file', function (): void {
    $sources = sessionStatusWriterSources();

    expect(array_keys($sources))->toContain(base_path('Modules/Sync/Internal/Transport/SyncSession.php'));
    expect(writtenSessionStatuses())->toContain('closed');
    expect(declaredSessionStatuses())->toContain('active');
});

it('has a writer for every session status the schema declares, and declares every one it writes', function (): void {
    $declared = declaredSessionStatuses();
    $written = writtenSessionStatuses();

    expect($written)->toBe($declared, implode("\n  ", [
        'The states sync_sessions.status can hold and the states Sync actually writes',
        'have to be one set. A declared state with no writer is a branch every reader',
        'carries and nothing can ever reach; a written state nothing declares is a row',
        'the schema does not describe.',
        'declared: '.implode(', ', $declared),
        'written:  '.implode(', ', $written),
    ]));
});

it('holds the enum and the database trigger to that same vocabulary', function (): void {
    $declared = declaredSessionStatuses();

    $cases = array_map(static fn (SyncSessionStatus $case): string => $case->value, SyncSessionStatus::cases());
    sort($cases);

    expect($cases)->toBe($declared, 'SyncSessionStatus is the one name for this vocabulary, so its cases are the vocabulary');

    $enforced = triggerEnforcedSessionStatuses();

    expect($enforced)->not->toBe([], 'no trigger guards sync_sessions.status, so the column holds whatever a writer hands it');

    foreach ($enforced as $trigger) {
        expect($trigger)->toBe($declared, 'a trigger enforcing a different set to the one declared is a third opinion');
    }
});

// The literal lists this file exists to retire. Both readers branched on the
// same three-value array, and neither could be corrected without finding the
// other — which is how they stayed wrong together.
it('leaves no reader branching on a status literal', function (): void {
    $offenders = [];

    $readers = [
        'Modules/Sync/Internal/Status/PeerSessionTally.php',
        'Modules/Sync/Public/Http/Livewire/SyncStatusSection.php',
        'Modules/Sync/Resources/views/livewire/sync-status-section.blade.php',
    ];

    foreach ($readers as $relative) {
        $source = (string) file_get_contents(base_path($relative));

        foreach (declaredSessionStatuses() as $value) {
            if (str_contains($source, "'".$value."'")) {
                $offenders[] = $relative.' still spells '.$value.' as a literal';
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A reader comparing against a quoted status is a second copy of the vocabulary.',
        'Read SyncSessionStatus instead. Offenders:',
        ...$offenders,
    ]));
});
