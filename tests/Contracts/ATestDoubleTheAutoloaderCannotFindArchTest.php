<?php

declare(strict_types=1);

use Tests\Contracts\Support\TopLevelDeclarations;

// A class declared at the top level of a *Test.php file is skipped by
// Composer's classmap -- the file is named for the test, not for the class, so
// no psr-4 rule maps the name back to it. It exists only because Pest required
// that one file, which makes three things true at once: a second file naming
// it fatals, two files declaring the same name in one shard take the whole
// parallel run down rather than one test, and `composer install` prints a
// warning per site until nobody reads warnings.
//
// The second of those was already being managed by hand when this landed:
// seven stub adapters in one module carried the initials of their own test
// file as a prefix -- Acoa, Afn, Sncr, Soja, Ofs, Oms, Atws -- and 4,284 free
// helper functions across 1,668 test files carry the same dodge. A convention
// nobody can enforce was standing in for a namespace.
// @link ../../.docs/conventions/arch-invariants.md#a-declaration-no-autoloader-reaches

// A pin names a site that keeps its declaration where Composer cannot reach it,
// with the reason. Each is re-checked against the walk below: when the site is
// moved or renamed the pin stops matching and fails here, so an exemption
// cannot outlive what earned it.
const DECLARATIONS_NO_AUTOLOADER_REACHES = [
    'app/PhpStan/Rules/Fixtures/BadBoundaryFixture.php declares BadBoundaryFixture' => 'the custom BoundaryRule fires on a class whose namespace is a neighbouring module private one, so a fixture that named its own directory would not be a subject of the rule at all. Being unreachable is the point twice over: were it autoloadable it would be real code inside that private namespace, which every boundary reader would then judge as shipped. phpstan.neon excludes the directory from the analysis for the same reason, and PhpStanBoundaryRuleTest points PHPStan at the file by path',
    'app/PhpStan/Rules/Fixtures/BadPageLayoutFixture.php declares BadPageLayoutFixture' => 'the page-layout rule reads a Livewire full-page component, which is a module-private class by construction; PhpStanPageLayoutMacroTest analyses the file by path',
    'app/PhpStan/Rules/Fixtures/GoodBoundaryFixture.php declares GoodBoundaryFixture' => 'the clean half of the BoundaryRule pair, which has to sit in the same namespace as the offending half or it would prove the rule silent for the wrong reason',
    'app/PhpStan/Rules/Fixtures/GoodPageLayoutFixture.php declares GoodPageLayoutFixture' => 'the clean half of the page-layout pair, same reason',
    'tests/Feature/InstallLaunchdCommandTest.php declares CaptureBootstrapInstallCommand' => 'the file is being edited by open PR #377, which moving the class would conflict with. This pin is temporary: it comes out with the move, and until then it fails the moment anyone else touches the declaration',
];

it('walks the tree it is about to read a verdict off', function (): void {
    expect(count(TopLevelDeclarations::testFiles()))->toBeGreaterThan(
        2000,
        'the walk found almost no test files, so every verdict below is a clean tree nobody looked at',
    );

    expect(count(TopLevelDeclarations::autoloadRoots()))->toBeGreaterThan(
        30,
        'composer.json declares a psr-4 rule per module test tree; reading only a handful of them would '
        .'report unreachable code as reachable',
    );
});

it('reads a declaration the file makes and not one it writes into a string', function (): void {
    $planted = <<<'SOURCE'
    <?php

    use Modules\Core\Public\Support\PatternScan;

    final class RealDouble extends Something {}

    interface AlsoReal {}

    $probe = <<<'PROBE'
    final class PlantedViolation extends Something
    {
        public function handle(): void {}
    }
    PROBE;

    $anonymous = new class extends Something {};
    $named = PatternScan::class;

    function makesOne(): void
    {
        class NotTopLevel {}
    }
    SOURCE;

    $read = TopLevelDeclarations::in($planted);

    expect(array_column($read['types'], 'name'))->toBe(
        ['RealDouble', 'AlsoReal'],
        'the reader must see the two real top-level declarations and none of: a class written into a heredoc, '
        .'an anonymous class, a ::class constant fetch, or a class nested inside a function body',
    );

    $namespace = 'Modules\\Core\\Tests\\Support';
    $namespaced = TopLevelDeclarations::in("<?php\n\nnamespace ".$namespace.";\n\nfinal class Moved {}\n");

    expect($namespaced)->toBe(['namespace' => $namespace, 'types' => [['kind' => 'class', 'name' => 'Moved', 'line' => 5]]])
        ->and(TopLevelDeclarations::qualify($namespace, 'Moved'))->toBe($namespace.'\\Moved');
});

it('keeps a test double in a file of its own rather than in the test that uses it', function (): void {
    $declaring = [];

    foreach (TopLevelDeclarations::testFiles() as $relative) {
        foreach (TopLevelDeclarations::inFile($relative)['types'] as $type) {
            $site = TopLevelDeclarations::site($relative, $type['name']);

            if (! array_key_exists($site, DECLARATIONS_NO_AUTOLOADER_REACHES)) {
                $declaring[] = $site.', a '.$type['kind'].' on line '.$type['line'];
            }
        }
    }

    expect($declaring)->toBe([], implode("\n  ", [
        'These test files declare a class, interface, trait or enum at their top level. Composer skips it, so '
            .'it exists only while the one file that declares it is loaded, and two of them sharing a name in '
            .'one shard is a fatal that takes the whole parallel run '
            .'down. Move each to a file of its own beside the module\'s other doubles, the way '
            .'Modules/Sync/tests/Support/CaptureSites.php does it: one class per file under the module\'s '
            .'tests/Support, namespaced Tests\\Support under the module, imported with a compound `use`. Do not '
            .'add a namespace to the test file itself; Pest resolves that file by path:',
        ...$declaring,
    ]));
});

it('leaves nothing under a psr-4 root that Composer builds no classmap entry for', function (): void {
    $unreachable = [];

    foreach (TopLevelDeclarations::skippedByComposer() as $site => $qualified) {
        if (! array_key_exists($site, DECLARATIONS_NO_AUTOLOADER_REACHES)) {
            $unreachable[] = $site.', which resolves to '.$qualified;
        }
    }

    expect($unreachable)->toBe([], implode("\n  ", [
        'Composer reads these files -- they sit under a declared psr-4 directory -- and no rule maps the name '
            .'they declare back to the path they sit at, so each is dropped from the classmap with a warning on '
            .'every `composer install`. Name the file after the class and put it where its namespace says, or '
            .'pin it in DECLARATIONS_NO_AUTOLOADER_REACHES with the reason it must stay unreachable:',
        ...$unreachable,
    ]));
});

it('holds every pin to a declaration that is still where it was pinned', function (): void {
    $walked = array_keys(TopLevelDeclarations::skippedByComposer());

    foreach (TopLevelDeclarations::testFiles() as $relative) {
        foreach (TopLevelDeclarations::inFile($relative)['types'] as $type) {
            $walked[] = TopLevelDeclarations::site($relative, $type['name']);
        }
    }

    $stale = array_values(array_diff(array_keys(DECLARATIONS_NO_AUTOLOADER_REACHES), $walked));

    expect($stale)->toBe([], implode("\n  ", [
        'These pins name a declaration the walk no longer produces. The site was moved, renamed or deleted, and '
            .'the exemption now excuses nothing while reading as considered. Delete the entry:',
        ...$stale,
    ]));

    expect(DECLARATIONS_NO_AUTOLOADER_REACHES)->not->toBeEmpty();
});
