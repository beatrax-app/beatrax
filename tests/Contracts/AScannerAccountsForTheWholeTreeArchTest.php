<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;

// A guard narrower than the claim it makes passes, and it passes because it
// never looked. Three shipped instances wore three different mechanisms: a
// hand-written root list that opened five directories of fourteen, a
// single-root walk that made app/ structurally invisible while a command
// raw-deleted from two travelling tables inside it, and a whole-file substring
// exemption that hid seventy-one files from the encryption guard.
//
// One shape underneath: a scanner's declared scope stopped describing the
// tree, and nothing was watching the difference. RepoTree is where a scope is
// declared; this is the guard that holds a declaration to the tree it claims.
//
// It reads a hand-written root list with the tokeniser rather than with a
// pattern, because the thing being looked for is a string literal and a name
// inside a comment or a docblock reads the same to a regex.
// @link ../../.docs/conventions/arch-invariants.md#a-scanner-accounts-for-the-whole-tree

const SCANNER_SEAM = 'tests/Contracts/Support/RepoTree.php';

// A shared walk allowed to name its own roots, each with the reason and a
// pattern re-checked against the file. When the file stops matching, the
// exemption has outlived what earned it and this guard fails there rather than
// waving the site on for another year.
const SCANNERS_NAMING_THEIR_OWN_ROOTS = [
    'tests/Contracts/Support/BackendSourceFiles.php' => [
        'reason' => 'the runtime domain code, which is what the rules reading it are about: money that names its currency, a date refused rather than normalised, a column a screen reads back. routes, config and bootstrap are wiring, database is schema and seed, and scripts runs on a build machine and never ships -- widening this walk would not find those rules more subjects, it would ask them about files they do not describe. The build scripts are not unguarded: the checked-regex seams reach them, which is where a give-up that blanks the Android manifest was found',
        'proves' => "base_path('Modules'), base_path('app')",
    ],
    'tests/Contracts/Support/SonarSourceFiles.php' => [
        'reason' => 'sonar.sources and nothing wider: a guard standing in for the hosted analysis fails on files the dashboard will never mention, which is the failure mode that gets a guard switched off',
        'proves' => 'sonar-project.properties',
    ],
    'tests/Contracts/Support/WireCallableMethods.php' => [
        'reason' => 'a Livewire component class is a Modules/**/Http/Livewire file by construction, and the caller walk beside it reads Blade and JavaScript together, which no PHP scope models; both narrowings can only report a reachable method as unreachable, never wave a dead one through',
        'proves' => 'Http/Livewire',
    ],
];

/** @return list<string> every top-level directory this repository keeps first-party source in */
function scannerRootNames(): array
{
    $named = array_values(array_unique([
        ...RepoTree::rootsHolding('.php'),
        ...RepoTree::rootsHolding('.blade.php'),
    ]));

    sort($named);

    return $named;
}

/**
 * The top-level root names $source writes out as string literals. Read from
 * the token stream so a root named in prose is not mistaken for one named in
 * code.
 *
 * @param  list<string>  $roots
 * @return list<string>
 */
function rootsNamedByHandIn(string $source, array $roots): array
{
    $named = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literal = substr($token[1], 1, -1);

            if (in_array($literal, $roots, true)) {
                $named[$literal] = true;
            }
        }
    }

    $found = array_keys($named);
    sort($found);

    return $found;
}

/** @return array<string, list<string>> shared scanner => the roots it writes out by hand */
function scannersNamingRootsByHand(): array
{
    $roots = scannerRootNames();
    $found = [];

    foreach ((array) glob(RepoTree::root().'/tests/Contracts/Support/*.php') as $path) {
        $relative = str_replace(RepoTree::root().'/', '', (string) $path);

        if ($relative === SCANNER_SEAM) {
            continue;
        }

        $named = rootsNamedByHandIn((string) file_get_contents((string) $path), $roots);

        if ($named !== []) {
            $found[$relative] = $named;
        }
    }

    ksort($found);

    return $found;
}

it('accounts for every root holding first-party code, in every scope a guard can ask for', function (): void {
    expect(RepoTree::SCOPES)->not->toBeEmpty();

    foreach (array_keys(RepoTree::SCOPES) as $scope) {
        $account = RepoTree::accountOf($scope);

        expect($account['unaccounted'])->toBe([], implode("\n  ", [
            'These top-level directories hold first-party '.RepoTree::scope($scope)['extension']
                .' that the scope "'.$scope.'" neither opens nor declines, so every guard reading that scope '
                .'reports a clean tree over code nobody looked at. Add each to `covers`, or to `declines` with '
                .'the reason it is somebody else\'s to read:',
            ...$account['unaccounted'],
        ]));

        expect($account['stale'])->toBe([], implode("\n  ", [
            'The scope "'.$scope.'" declines these roots, and none of them holds a file of its kind at all. '
                .'The refusal excuses nothing and reads as considered. Delete the entry:',
            ...$account['stale'],
        ]));

        expect($account['silent'])->toBe([], implode("\n  ", [
            'The scope "'.$scope.'" claims these roots and its walk reached no file in any of them:',
            ...$account['silent'],
        ]));
    }
});

// A scope that reads almost nothing reports the same clean tree as a scope that
// found nothing wrong, so the walk's own size is asserted before any guard
// reads a verdict off it. The floors sit far under today's counts -- 9,333,
// 6,500 and 276 -- so only a broken walk trips them.
it('gives every scope a floor its walk cannot quietly fall under', function (string $scope, int $floor): void {
    expect(count(RepoTree::files($scope)))->toBeGreaterThan(
        $floor,
        'RepoTree::files("'.$scope.'") returned '.count(RepoTree::files($scope)).' files, which is too few to '
        .'have read the tree. Every guard on this scope would pass over almost nothing.'
    );
})->with([
    'every PHP file' => [RepoTree::EVERY_PHP_FILE, 8000],
    'the production PHP' => [RepoTree::PRODUCTION_PHP, 5000],
    'every Blade view' => [RepoTree::EVERY_BLADE_VIEW, 200],
]);

// A guard that cannot go red is a guard that says nothing, and the three
// verdicts above are read off one list each. These plant the three drifts
// against the reader rather than against the tree, so a rewrite of the account
// cannot quietly stop finding them.
it('finds each way a scope stops describing the tree', function (): void {
    $scope = [
        'extension' => '.php',
        'covers' => ['Modules', 'scripts'],
        'declines' => ['tests' => 'the suite names the forbidden shapes on purpose'],
        'skips' => [],
    ];

    $tracked = ['Modules/A.php', 'scripts/B.php', 'tests/C.php', 'newroot/D.php'];

    expect(RepoTree::account($scope, $tracked, ['Modules/A.php', 'scripts/B.php'])['unaccounted'])
        ->toBe(['newroot'], 'a root that appeared in the tree and no list mentions went unreported');

    expect(RepoTree::account($scope, $tracked, ['Modules/A.php'])['silent'])
        ->toBe(['scripts'], 'a covered root the walk stopped reaching went unreported');

    $stale = ['extension' => '.php', 'covers' => ['Modules'], 'declines' => ['gone' => 'a root deleted years ago'], 'skips' => []];

    expect(RepoTree::account($stale, ['Modules/A.php'], ['Modules/A.php'])['stale'])
        ->toBe(['gone'], 'an exemption excusing a root that holds nothing went unreported');

    expect(RepoTree::account($scope, $tracked, ['Modules/A.php', 'scripts/B.php']))
        ->toBe(['unaccounted' => ['newroot'], 'stale' => [], 'silent' => []]);
});

it('reads a root named in code and not one named in prose', function (): void {
    $roots = scannerRootNames();

    expect($roots)->toContain('Modules')->toContain('app')->toContain('.claude')->toContain('tools');

    $names = "<?php\n// walks Modules and app\n/** @return list<string> under scripts */\n\$roots = ['config', 'routes'];\n";

    expect(rootsNamedByHandIn($names, $roots))->toBe(['config', 'routes']);
});

it('leaves the seam the one place a shared scanner writes a root name out', function (): void {
    $offenders = [];

    foreach (scannersNamingRootsByHand() as $scanner => $named) {
        if (! array_key_exists($scanner, SCANNERS_NAMING_THEIR_OWN_ROOTS)) {
            $offenders[] = $scanner.' names '.implode(', ', $named);
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These shared walks write out their own list of top-level directories. A list is a claim about the '
            .'tree that nothing re-checks, and every one of them has already drifted: five roots of fourteen '
            .'in one, six in another, two in the walk that calls itself "every backend PHP file". Take a scope '
            .'from '.SCANNER_SEAM.', which is held to the tree by the test above. If the narrowness is '
            .'deliberate, add it to SCANNERS_NAMING_THEIR_OWN_ROOTS with the reason and a pattern that proves '
            .'the reason still reads:',
        ...$offenders,
    ]));

    expect(array_keys(SCANNERS_NAMING_THEIR_OWN_ROOTS))->toBe(
        array_keys(array_intersect_key(SCANNERS_NAMING_THEIR_OWN_ROOTS, scannersNamingRootsByHand())),
        'An entry in SCANNERS_NAMING_THEIR_OWN_ROOTS names a file that no longer writes a root out by hand. '
        .'The exemption excuses nothing — delete it.'
    );
});

it('re-checks the reason each hand-written root list was allowed to keep', function (): void {
    $expired = [];

    foreach (SCANNERS_NAMING_THEIR_OWN_ROOTS as $scanner => $pin) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$scanner);

        if (! str_contains($source, $pin['proves'])) {
            $expired[] = $scanner.' no longer holds "'.$pin['proves'].'", so it is no longer '.$pin['reason'];
        }
    }

    expect($expired)->toBe([], implode("\n  ", [
        'These exemptions have outlived what earned them. The file was allowed to name its own roots for a '
            .'reason, and the reason no longer reads:',
        ...$expired,
    ]));
});
