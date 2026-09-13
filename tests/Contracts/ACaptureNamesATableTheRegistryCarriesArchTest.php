<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

/**
 * @link ../../.docs/features/sync/merge-registry-authoring.md
 */

// `SyncOffOpSink` drops a write whose reader has never enabled sync, and that is
// only lossless because `PreSyncHistoryCapture` walks the registry and announces
// the whole history when they pair. A table a capture site can name but the
// registry does not carry is dropped and then never announced.

const CAPTURE_DISPATCH = '/new EntityMutated\(\s*(?:table:\s*)?([^,]+),/';

const CAPTURE_TABLE_LITERAL = "/^'([^']+)'$/";

// A capture whose table is decided at runtime cannot be read here at all. Each
// one is a hole the size of whatever that variable holds, so they are pinned by
// file: a fourth has to say why it cannot name its table where a reader sees it.
const CAPTURE_DYNAMIC_SITES = [
    'Modules/Counterparties/Internal/Actions/MergeCounterparties.php',
    'Modules/Migration/Internal/Pipeline/EntityChangeApplier.php',
    'Modules/Sync/Public/Services/DependentRowCascade.php',
];

/**
 * @return list<string>
 */
function captureSourceFiles(): array
{
    $files = [];

    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))) as $entry) {
        $path = $entry->getPathname();

        if ($entry->isFile() && $entry->getExtension() === 'php' && ! str_contains($path, '/tests/')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * Every `new EntityMutated(...)` in the shipped tree, as
 * `['file' => …, 'line' => …, 'table' => … or null]`.
 *
 * @return list<array{file:string,line:int,table:string|null}>
 */
function captureDispatches(): array
{
    $found = [];

    foreach (captureSourceFiles() as $file) {
        $source = (string) file_get_contents($file);

        foreach (PatternScan::setsWithOffsets(CAPTURE_DISPATCH, $source) as $set) {
            $literal = PatternScan::first(CAPTURE_TABLE_LITERAL, trim($set[1][0]));

            $found[] = [
                'file' => str_replace(base_path().'/', '', $file),
                'line' => substr_count(substr($source, 0, (int) $set[0][1]), "\n") + 1,
                'table' => $literal === [] ? null : $literal[1],
            ];
        }
    }

    return $found;
}

it('names, at every capture site, a table the merge registry carries', function (): void {
    $registry = new MergeRulesRegistry;
    $dispatches = captureDispatches();
    $offenders = [];

    foreach ($dispatches as $dispatch) {
        if ($dispatch['table'] !== null && ! $registry->isRegistered($dispatch['table'])) {
            $offenders[] = sprintf('%s:%d captures for `%s`', $dispatch['file'], $dispatch['line'], $dispatch['table']);
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These dispatch a capture for a table the merge registry does not carry. The write is',
        'dropped for a reader who has not enabled sync, and the pre-sync walk that would have',
        'announced it later reads the registry — so it is never announced either. Add the table',
        'to MergeRulesRegistry, or stop dispatching for it:',
        ...$offenders,
    ]));

    // The walk read something. A scope that moves under this rule reports no
    // offenders for the same reason a correct tree does.
    expect(count($dispatches))->toBeGreaterThan(40);
});

it('keeps to the three capture sites that decide their table at runtime', function (): void {
    $dynamic = [];

    foreach (captureDispatches() as $dispatch) {
        if ($dispatch['table'] === null) {
            $dynamic[] = $dispatch['file'];
        }
    }

    $dynamic = array_values(array_unique($dynamic));
    sort($dynamic);

    expect($dynamic)->toBe(CAPTURE_DYNAMIC_SITES, implode("\n  ", [
        'A capture site that names its table through a variable cannot be checked against the',
        'registry by reading it. That is the same blindness a constant caused in',
        'BoundedReadArchTest, and it fails the same quiet way: an unreadable name is not a',
        'wrong one, it is no answer at all. Name the table where a reader can see it, or add',
        'the file here with the reason it cannot.',
    ]));
});
