<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Every chain_links row is written through ChainLinkInsertHelper, which is what
// makes the evidence JSON byte-identical and the pair-uniqueness guard one
// guard. The demo seeder wrote its own INSERT through an Eloquent cast (default
// json_encode flags, a narrower duplicate check) and the hint listener hand-
// copied both; the copies had already drifted apart from the original.

const CHAIN_LINK_INSERT_ALLOWED = 'Modules/Chains/Internal/ChainLinkInsertHelper.php';

// The seam is exempted whole because the whole file is the write path, and the
// last case here re-runs this pattern against it: an exemption that outlives
// the insert it was granted for fails there rather than waving the site on.
const CHAIN_LINK_INSERT_SEAM_PROVES = "/table\('chain_links'\)->insert\(/";

/** @return list<string> the shapes that put a row into chain_links */
function chainLinkInsertPatterns(): array
{
    return [
        // Both quotings, because a rule keyed on one of them reads a rewrite of
        // the same call as a clean tree.
        '#chain_links[\'"]\s*\)\s*->\s*insert(GetId|OrIgnore|Using)?\s*\(#',
        '#ChainLink::(query\(\)->)?(create|insert|forceCreate|firstOrCreate|updateOrCreate)\s*\(#',
    ];
}

/**
 * @param  array<string, string>  $sources  repo-relative path => contents
 * @return list<string>
 */
function chainLinkInsertOffenders(array $sources): array
{
    $offenders = [];

    foreach ($sources as $relative => $contents) {
        foreach (chainLinkInsertPatterns() as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = $relative;

                break;
            }
        }
    }

    return $offenders;
}

/** @return array<string, string> repo-relative path => contents */
function chainLinkWriteSources(): array
{
    $sources = [];
    foreach (['Modules', 'app', 'database'] as $directory) {
        $root = base_path($directory);
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            // A test builds the rows it is about to read back, which is not a
            // production write path and never shares this guard.
            if (str_contains($relative, '/tests/') || $relative === CHAIN_LINK_INSERT_ALLOWED) {
                continue;
            }
            $sources[$relative] = (string) file_get_contents($path);
        }
    }

    return $sources;
}

it('has one chain_links INSERT site, and it is ChainLinkInsertHelper', function (): void {
    $sources = chainLinkWriteSources();

    expect(count($sources))->toBeGreaterThan(
        3000,
        'The walk over Modules, app and database read '.count($sources).' files, which is too few to have '
        .'read the tree. A walk that reads nothing reports the same clean result as one that found nothing wrong.'
    );

    $offenders = chainLinkInsertOffenders($sources);

    expect($offenders)->toBe([], implode("\n", [
        'These write chain_links without going through '.CHAIN_LINK_INSERT_ALLOWED.':',
        ...$offenders,
    ]));
});

it('finds each shape that writes a chain_links row, and passes the ones that do not', function (): void {
    $writes = [
        'a query-builder insert' => "\$connection->table('chain_links')->insert(\$row);",
        'the same call double-quoted' => '$connection->table("chain_links")->insert($row);',
        'an insertOrIgnore' => "\$connection->table('chain_links')->insertOrIgnore(\$rows);",
        'an Eloquent create' => 'ChainLink::create($attributes);',
        'an Eloquent updateOrCreate through query()' => 'ChainLink::query()->updateOrCreate($keys, $values);',
    ];

    foreach ($writes as $shape => $source) {
        expect(chainLinkInsertOffenders(['Fixture.php' => $source]))
            ->toBe(['Fixture.php'], 'the guard read past '.$shape.', so it would read past the real one');
    }

    $reads = [
        'a read of the same table' => "\$connection->table('chain_links')->where('id', \$id)->first();",
        'an update of an existing row' => "\$connection->table('chain_links')->update(['evidence' => \$json]);",
        'a differently named table' => "\$connection->table('chain_link_hints')->insert(\$row);",
    ];

    foreach ($reads as $shape => $source) {
        expect(chainLinkInsertOffenders(['Fixture.php' => $source]))
            ->toBe([], 'the guard reported '.$shape.' as a write, which would make its offender list unreadable');
    }
});

it('still holds the seam to the insert its exemption was granted for', function (): void {
    $seam = base_path(CHAIN_LINK_INSERT_ALLOWED);

    expect(is_file($seam))->toBeTrue(
        CHAIN_LINK_INSERT_ALLOWED.' is exempted from the rule above and no longer exists. The exemption '
        .'excuses nothing and the write path has moved somewhere no rule covers.'
    );

    expect(PatternScan::matches(CHAIN_LINK_INSERT_SEAM_PROVES, (string) file_get_contents($seam)))->toBeTrue(
        CHAIN_LINK_INSERT_ALLOWED.' was exempted because it is the one place a chain_links row is inserted, '
        .'and it no longer holds that insert. Either the write moved to a file this rule now reports, or the '
        .'exemption has outlived what earned it — delete it.'
    );
});
