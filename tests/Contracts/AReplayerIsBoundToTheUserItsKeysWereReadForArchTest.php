<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// The replayer reads its confirmed key set once, for one user, and takes the
// scope to write into per call. Left unbound, a device only one household
// member confirmed authors a write the other's ledger accepts as its own — and
// the entry is re-stamped onto that scope, so nothing downstream can tell.

/** @return list<string> the argument text of every `new OpLogReplayer(...)` in $source */
function replayerScopeConstructionArguments(string $source): array
{
    $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    $needle = 'new OpLogReplayer(';
    $found = [];
    $at = 0;

    while (($at = strpos($source, $needle, $at)) !== false) {
        $cursor = $start = $at + strlen($needle);
        $depth = 1;

        while ($cursor < strlen($source) && $depth > 0) {
            $depth += match ($source[$cursor]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $cursor++;
        }

        $found[] = substr($source, $start, $cursor - $start - 1);
        $at = $cursor;
    }

    return $found;
}

it('names the user its key set was read for at every production replayer construction', function (): void {
    $unbound = [];
    $seen = 0;

    foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'new OpLogReplayer(')) {
            continue;
        }

        $relative = str_replace(RepoTree::root().'/', '', $path);

        foreach (replayerScopeConstructionArguments($source) as $arguments) {
            $seen++;

            if (! str_contains($arguments, 'deviceKeysUserId')) {
                $unbound[] = $relative;
            }
        }
    }

    // Without this the rule passes by finding nothing: a rename of the class,
    // or a factory that moves the construction out of the scanned tree, reads
    // exactly like a tree that obeys it.
    expect($seen)->toBeGreaterThanOrEqual(
        5,
        'the scan found no replayer construction to judge, so it is proving nothing',
    );

    expect($unbound)->toBe(
        [],
        'a replayer built without deviceKeysUserId admits any confirmed device into any scope',
    );
});
