<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The relay carries blobs it cannot read, and that is the whole of what it
// cannot do. It still watches sizes, timing, and which device ids exchange
// traffic. Both halves have to hold: what it can see is written down, and
// nothing shipped tells a reader that watching is defeated.

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

    expect($docs)->not->toBeEmpty();

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

    expect(is_file($path))->toBeTrue();

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

    $claim = '/\b(?:untraceable|untrackable|unhackable|(?:military|bank)[- ]grade|traffic[- ]analysis'
        .'|zero[- ]knowledge|anonymi[sz]ed|no metadata|cannot be traced|(?:nobody|no one) can see'
        .'|completely (?:private|anonymous))\b/i';

    $offenders = [];

    foreach ($files as $path) {
        if (PatternScan::matches($claim, (string) file_get_contents($path))) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([], 'the relay sees message sizes, timing, and which device ids exchange traffic, '
        .'and none of that is defended against. Copy that names the attack, or borrows a phrase that implies it '
        .'was beaten, promises a reader something no code here delivers — say what is visible instead: '
        .implode(', ', $offenders));
});
