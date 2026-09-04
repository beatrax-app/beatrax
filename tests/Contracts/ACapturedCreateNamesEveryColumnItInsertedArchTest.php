<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A create payload is written by hand beside the insert it describes, and the
// two drift: envelope_moves shipped a `memo` the reader typed straight into
// the insert and never into the event, so the memo reached no other device.
// Nothing downstream notices — the row syncs, only emptier than it was.
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
            $offenders[] = str_replace($root.'/', '', $file).' inserts '.implode(', ', $missing).' and announces neither';
        }
    }

    sort($offenders);

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
    $offenders = capturedCreateOffenders(base_path('Modules'));

    expect($offenders)->toBe([], implode("\n", [
        'These writers insert a column their own create event never names, so the',
        'column reaches no other device:',
        ...$offenders,
        '',
        'Add it to dirtyFields, or build one array and use it for both.',
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
    expect($offenders[0])->toContain('memo');
});
