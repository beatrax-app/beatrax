<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The comparison is whole-file, so a file inserting into one table and
// announcing an edit to another reads as a create that lost its columns. These
// rows travel whole instead of as a hand-written payload, each with the seam
// that sends every column and a pattern re-run against it.
// Pinned per column, so a fourth unannounced one in the same file still fails.
const CREATES_CAPTURED_WHOLESALE = [
    'Migration/Internal/Pipeline/PromoteStagingToDomain.php' => [
        'columns' => ['raw_file_path', 'sha256', 'uploaded_at'],
        'reason' => "the only create event this file dispatches names `transactions`; the `import_runs` row it inserts travels as a parent of those transactions, captured by ImportSyncCapture off the live foreign key and written out column by column. All three are in the registry's `_create_required` for the table, so they cannot be struck from it either",
        'announcedBy' => 'Modules/Sync/Internal/OpLog/OpLogBackfiller.php',
        'proves' => '/writeCreateRow\(\$table, \$pk, \$this->plaintext->fields\(/',
    ],
];

// A create payload is written by hand beside the insert it describes, and the
// two drift: envelope_moves shipped a `memo` the reader typed straight into
// the insert and never into the event, so the memo reached no other device.
// Nothing downstream notices — the row syncs, only emptier than it was.
/**
 * @return array<string, list<string>> file, relative to $root, => the columns it inserts and never announces
 */
function capturedCreateOffenders(string $root): array
{
    // Seeded from the op envelope by OpLogEntryApplier, which ignores any
    // wire-supplied copy; and the timestamps OpLogWriter reads back itself.
    $suppliedElsewhere = ['id', 'user_id', 'created_at', 'updated_at'];

    $offenders = [];
    foreach (capturedCreatePhpFiles($root) as $file) {
        $source = (string) file_get_contents($file);
        if (! str_contains($source, 'dirtyFields:')) {
            continue;
        }

        $inserted = capturedCreateKeys($source, '/->(?:insertGetId|insert|updateOrInsert)\s*\(\s*\[/');
        $announced = capturedCreateKeys($source, '/dirtyFields:\s*\[/');
        if ($inserted === [] || $announced === []) {
            continue;
        }

        $missing = array_values(array_diff($inserted, $announced, $suppliedElsewhere));
        if ($missing !== []) {
            sort($missing);
            $offenders[str_replace($root.'/', '', $file)] = $missing;
        }
    }

    ksort($offenders);

    return $offenders;
}

/**
 * @return list<string>
 */
function capturedCreatePhpFiles(string $root): array
{
    $files = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        $path = (string) $entry;
        if (str_ends_with($path, '.php') && ! str_contains($path, '/tests/')) {
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// The keys of every array literal opened by $opener. Brace-counted rather than
// matched, because these literals nest and a regex cannot see where they end.
/**
 * @return list<string>
 */
function capturedCreateKeys(string $source, string $opener): array
{
    $keys = [];
    $matches = PatternScan::allWithOffsets($opener, $source);

    foreach ($matches[0] as [$text, $offset]) {
        $start = $offset + strlen($text) - 1;
        $depth = 0;
        for ($i = $start; $i < strlen($source); $i++) {
            $depth += $source[$i] === '[' ? 1 : ($source[$i] === ']' ? -1 : 0);
            if ($depth === 0) {
                $found = PatternScan::all("/'([a-z_][a-z0-9_]*)'\s*=>/", substr($source, $start, $i - $start + 1));
                $keys = [...$keys, ...$found[1]];
                break;
            }
        }
    }

    return array_values(array_unique($keys));
}

it('announces every column a captured create actually inserted', function (): void {
    $unpinned = [];

    foreach (capturedCreateOffenders(base_path('Modules')) as $file => $columns) {
        $left = array_values(array_diff($columns, CREATES_CAPTURED_WHOLESALE[$file]['columns'] ?? []));

        if ($left !== []) {
            $unpinned[] = $file.' inserts '.implode(', ', $left).' and announces neither';
        }
    }

    expect($unpinned)->toBe([], implode("\n", [
        'These writers insert a column their own create event never names, so the',
        'column reaches no other device:',
        ...$unpinned,
        '',
        'Add it to dirtyFields, or build one array and use it for both. A row that',
        'travels whole rather than as a hand-written payload is pinned in',
        'CREATES_CAPTURED_WHOLESALE with the seam that sends every column of it.',
    ]));
});

it('keeps no pin for a create that now names its own columns', function (): void {
    $offenders = capturedCreateOffenders(base_path('Modules'));

    $stale = [];
    foreach (CREATES_CAPTURED_WHOLESALE as $file => $pin) {
        foreach (array_diff($pin['columns'], $offenders[$file] ?? []) as $column) {
            $stale[] = $file.' is pinned for '.$column.', which it no longer inserts unannounced';
        }
    }

    sort($stale);

    expect($stale)->toBe([], implode("\n", [
        'A pin that has outlived what earned it silently widens the exemption:',
        ...$stale,
        '',
        'Delete the entry from CREATES_CAPTURED_WHOLESALE.',
    ]));
});

it('re-checks the reason every wholesale pin was granted for', function (): void {
    $broken = [];

    foreach (CREATES_CAPTURED_WHOLESALE as $file => $pin) {
        $path = base_path($pin['announcedBy']);

        if (! is_file($path)) {
            $broken[] = $file.' points at '.$pin['announcedBy'].', which does not exist';

            continue;
        }

        if (! PatternScan::matches($pin['proves'], (string) file_get_contents($path))) {
            $broken[] = $file.' is exempt because "'.$pin['reason'].'", and '.$pin['announcedBy'].' no longer reads that way';
        }
    }

    sort($broken);

    expect($broken)->toBe([], implode("\n", [
        'An exemption whose reason no longer holds is a gap nobody chose:',
        ...$broken,
    ]));
});

it('reports a create that leaves one of its inserted columns unannounced', function (): void {
    $planted = sys_get_temp_dir().'/captured-create-'.bin2hex(random_bytes(6));
    mkdir($planted);
    // Assembled here rather than written as a literal so this fixture cannot
    // be read as a real offender by the guard scanning its own tree.
    $insert = '->insert'.'GetId([';
    file_put_contents($planted.'/Writer.php', implode("\n", [
        '<?php', '$id = $c->table("t")'.$insert,
        "'amount_minor' => 1,", "'memo' => \$memo,", ']);',
        'new Mutated(', 'dirtyFields: [', "'amount_minor' => 1,", '],', ');',
    ]));

    $offenders = capturedCreateOffenders($planted);
    unlink($planted.'/Writer.php');
    rmdir($planted);

    expect($offenders)->toHaveCount(1);
    expect($offenders['Writer.php'])->toBe(['memo']);
});
