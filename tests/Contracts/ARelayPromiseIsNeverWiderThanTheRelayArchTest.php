<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The relay carries blobs it cannot read, and that is the whole of what it
// cannot do. It still watches sizes, timing, and which device ids exchange
// traffic. Both halves have to hold: what it can see is written down, and
// nothing shipped tells a reader that watching is defeated.

// The phrases that promise a reader the relay was beaten rather than trusted.
// Read case-insensitively, and only as whole words: "grade" and "private" carry
// their ordinary meanings everywhere else in the copy.
const RELAY_PROMISE_OVERCLAIM = '/\b(?:untraceable|untrackable|unhackable|(?:military|bank)[- ]grade|traffic[- ]analysis'
    .'|zero[- ]knowledge|anonymi[sz]ed|no metadata|cannot be traced|(?:nobody|no one) can see'
    .'|completely (?:private|anonymous))\b/i';

/** @return list<string> the relay mailbox's own columns, in declaration order */
function relayPromiseMailboxColumns(): array
{
    $migrations = glob(base_path('Modules/Sync/Database/Migrations/*create_relay_mailbox_table.php')) ?: [];

    $columns = [];

    foreach ($migrations as $path) {
        $source = (string) file_get_contents($path);

        foreach (PatternScan::sets('/\$table->\w+\(\s*\'(\w+)\'/', $source) as $set) {
            $columns[] = $set[1];
        }
    }

    return array_values(array_unique($columns));
}

/** @return list<string> every page under the sync feature documentation */
function relayPromiseSyncDocs(): array
{
    return glob(base_path('.docs/features/sync/*.md')) ?: [];
}

/** @return list<string> every file that carries copy a reader can be shown */
function relayPromiseShippedCopy(): array
{
    $found = [];

    foreach ([base_path('lang'), base_path('resources/views'), base_path('Modules')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $isCopy = str_contains($path, '/Resources/lang/') || str_ends_with($path, '.blade.php')
                || str_starts_with($path, base_path('lang'));

            if ($file->isFile() && $isCopy && ! str_contains($path, '/tests/')) {
                $found[] = $path;
            }
        }
    }

    sort($found);

    return $found;
}

it('writes down every column of the mailbox a relay operator can read', function (): void {
    $columns = relayPromiseMailboxColumns();

    expect($columns)->toHaveCount(
        6,
        'the mailbox column set is pinned in both directions: one added here is one more thing the operator of '
        .'somebody else\'s machine can read, and one gone is a disclosure that has outlived what it described. '
        .'A count of zero means the schema was never read, which would make every column below look documented. '
        .'Found: '.implode(', ', $columns),
    );

    $docs = relayPromiseSyncDocs();

    expect($docs)->not->toBeEmpty(
        'No page was found under .docs/features/sync, so every column below reads as undocumented — or, with the '
        .'filter reversed, as documented by a file nobody opened.'
    );

    $documented = '';
    foreach ($docs as $path) {
        $documented .= (string) file_get_contents($path);
    }

    $undocumented = array_values(array_filter(
        $columns,
        static fn (string $column): bool => ! str_contains($documented, $column),
    ));

    expect($undocumented)->toBe([], 'a column on the relay mailbox is a thing the operator of somebody else\'s '
        .'machine can read, so it is disclosed in the sync documentation before it ships. These are not named '
        .'anywhere under .docs/features/sync: '.implode(', ', $undocumented));
});

it('states in the documentation what the relay operator is left holding', function (): void {
    $path = base_path('.docs/features/sync/architecture.md');

    expect(is_file($path))->toBeTrue(
        $path.' is the page this claim is written on, and it is not there. A disclosure nobody can read is not one.'
    );

    $source = (string) file_get_contents($path);

    expect(PatternScan::matches('/operator\s+learns only:/', $source))->toBeTrue(
        'the observable set is a claim with a number in it, so it is stated once, in prose, rather than left '
        .'for a reader to reconstruct from the schema',
    );
});

it('claims nothing anywhere in its copy about defeating an observer who is watching', function (): void {
    $files = relayPromiseShippedCopy();

    expect(count($files))->toBeGreaterThan(
        1000,
        'the copy walk resolved almost nothing, and a walk that reads nothing reports a clean tree',
    );

    $offenders = [];

    foreach ($files as $path) {
        if (PatternScan::matches(RELAY_PROMISE_OVERCLAIM, (string) file_get_contents($path))) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([], 'the relay sees message sizes, timing, and which device ids exchange traffic, '
        .'and none of that is defended against. Copy that names the attack, or borrows a phrase that implies it '
        .'was beaten, promises a reader something no code here delivers — say what is visible instead: '
        .implode(', ', $offenders));
});

// A guard that cannot go red says nothing, and the sweep above is read off one
// pattern. It is checked against the phrases it was written for rather than
// against the tree, so a rewrite cannot quietly stop finding them.
it('reads a promise the relay cannot keep, and leaves the ordinary words alone', function (string $copy, bool $overclaims): void {
    expect(PatternScan::matches(RELAY_PROMISE_OVERCLAIM, $copy))->toBe(
        $overclaims,
        'The reader answered '.var_export(! $overclaims, true).' for copy it has to read as '
        .($overclaims ? 'an overclaim' : 'ordinary prose').': '.$copy
    );
})->with([
    'a claim of untraceability' => ['Your sync is untraceable.', true],
    'a borrowed grade' => ['Protected with military-grade encryption.', true],
    'naming the attack as beaten' => ['Immune to traffic-analysis.', true],
    'the spelling with an s' => ['Every hop is anonymised.', true],
    'a plain statement of what is visible' => ['The relay sees message sizes, timing and device ids.', false],
    'the word private on its own' => ['Your ledger stays private to your household.', false],
    'a grade that is not a claim' => ['Choose the grade of the account.', false],
]);
