<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The comparison is whole-file, so a file inserting into one table and
// announcing an edit to another reads as a create that lost its columns. A row
// that travels whole instead of as a hand-written payload is pinned here, per
// column, with the seam that sends every column and a pattern re-run against it
// — so a fourth unannounced column in the same file still fails.
//
// Empty, and that is the current state of the tree rather than a disabled rule:
// the one entry it held was PromoteStagingToDomain's `import_runs` insert, and
// that file now announces nothing at all, which puts it outside what this rule
// compares. The rule still walks every file under Modules/.
const CREATES_CAPTURED_WHOLESALE = [];

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
        capturedCreateWalkRead(1, 0);
        $source = (string) file_get_contents($file);
        if (! str_contains($source, 'dirtyFields:')) {
            continue;
        }

        $inserted = capturedCreateKeys($source, '/->(?:insertGetId|insert|updateOrInsert)\s*\(\s*\[/');
        $announced = capturedCreateKeys($source, '/dirtyFields:\s*\[/');
        if ($inserted === [] || $announced === []) {
            continue;
        }

        capturedCreateWalkRead(0, 1);

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
 * Adds to, and reads back, what the whole walk opened: the files it read, and
 * the ones holding both an insert payload and a create event to compare it
 * against. A walk that compared nothing reports a clean tree.
 *
 * @return array{files: int, compared: int}
 */
function capturedCreateWalkRead(int $files = 0, int $compared = 0): array
{
    static $total = ['files' => 0, 'compared' => 0];

    $total['files'] += $files;
    $total['compared'] += $compared;

    return $total;
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
    $offenders = capturedCreateOffenders(base_path('Modules'));
    $walk = capturedCreateWalkRead();

    // Far under the ~6,600 files and 16 insert/announce pairs the tree holds. A
    // walk that opened nothing, or a brace reader that found no payload, both
    // report the same empty offender list a correct tree does.
    expect($walk['files'])->toBeGreaterThan(
        2000,
        'The walk opened '.$walk['files'].' files under Modules/, which is too few to have read the tree at all.',
    );
    expect($walk['compared'])->toBeGreaterThan(
        5,
        'The walk compared '.$walk['compared'].' insert payloads against their own create events, so the array '
        .'reader stopped rather than the tree getting cleaner.',
    );

    foreach ($offenders as $file => $columns) {
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

    expect($offenders)->toHaveCount(1, 'The planted writer inserts a column its own create event never names, and the reader missed it.');
    expect($offenders['Writer.php'])->toBe(
        ['memo'],
        'The reader either missed the unannounced column or reported an announced one beside it.',
    );
});
