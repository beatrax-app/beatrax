<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/arch-invariants.md
 */

// A page that names a guard makes a promise about the build. A reviewer reads
// "the noXxx invariant forbids this", trusts the suite, approves, and nothing
// fails. Twenty-seven such names were carried in the documentation and existed
// nowhere else in the tree, and four test classes were named that no file
// answers to.
//
// Both directions cost something. A name that never existed is a protection a
// reviewer believes is there. A name that was renamed reads as a guard someone
// deleted, which is how a second copy of it comes to be written.
//
// These two shapes are checkable because the whole claim is that a name is in
// the tree. The prose around them is not checkable and this does not pretend
// otherwise: it holds the citations, not the sentences.

const DOC_SYMBOL_PAGE_ROOTS = ['.docs', 'README.md', 'CONTRIBUTING.md', 'NOTICE.md', 'SECURITY.md', 'AGENTS.md'];

// The blank page a new module's docs are copied from. Its citations are shaped
// like citations on purpose, so a reader can see where their own go, and they
// name nothing because there is nothing yet to name.
const DOC_SYMBOL_PAGES_NAMING_NOTHING_REAL = '.docs/features/_template/';

// Not RepoTree, unlike the suite walk below. Five of these roots are files at
// the top level, and RepoTree covers directories only — rootsIn() drops a
// depth-1 path outright, so AGENTS.md and its four neighbours are unreachable
// through files() and invisible to its accounting. Re-pointing would drop them.
/** @return list<string> every page a reader of this repository is handed */
function docSymbolPages(): array
{
    $pages = [];

    foreach (DOC_SYMBOL_PAGE_ROOTS as $root) {
        $path = base_path($root);

        if (is_file($path)) {
            $pages[] = $path;

            continue;
        }

        foreach (Finder::create()->files()->in($path)->name('*.md') as $file) {
            $pages[] = $file->getPathname();
        }
    }

    $pages = array_values(array_filter(
        $pages,
        static fn (string $path): bool => ! str_contains($path, DOC_SYMBOL_PAGES_NAMING_NOTHING_REAL),
    ));

    sort($pages);

    return $pages;
}

// Memoised: four rules read the same two walks, and the suite walk alone opens
// well over two thousand files. RepoTree carries the roots, so a suite that
// grew a third home is this rule's subject without anyone re-listing it, and a
// covered root that goes silent raises there rather than narrowing here.
/** @return list<string> every PHP file the suite is made of */
function docSymbolSuiteFiles(): array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    return $cached = array_values(array_filter(
        RepoTree::files(RepoTree::EVERY_PHP_FILE),
        static fn (string $path): bool => str_contains($path, '/tests/'),
    ));
}

/**
 * The identifiers the pages name, as `identifier => the pages naming it`.
 *
 * Read from inside backticks only. A bare word in prose is English; a
 * backticked one is the author pointing at a symbol.
 *
 * @return array<string, list<string>>
 */
function docSymbolsNamed(string $pattern): array
{
    $named = [];

    foreach (docSymbolPages() as $page) {
        $relative = str_replace(base_path().'/', '', $page);

        foreach (PatternScan::all($pattern, (string) file_get_contents($page))[1] as $symbol) {
            $named[$symbol][] = $relative;
        }
    }

    foreach ($named as $symbol => $pages) {
        $named[$symbol] = array_values(array_unique($pages));
    }

    ksort($named);

    return $named;
}

// A leading path and a .php suffix are both optional, because a page cites a
// test either way and the class name is the half that has to resolve.
const DOC_SYMBOL_TEST_PATTERN = '/`(?:[\w\/.-]*\/)?([A-Z][A-Za-z0-9]*Test)(?:\.php)?`/';

/**
 * Every arch invariant the suite declares, read off its own test names: the
 * convention is a trailing `(theName)` on the description.
 *
 * @return list<string>
 */
function docSymbolDeclaredInvariants(): array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $declared = [];

    foreach (docSymbolSuiteFiles() as $path) {
        $literals = PatternScan::all(
            '/\b(?:it|test|describe)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/',
            (string) file_get_contents($path),
        )[1];

        foreach ($literals as $description) {
            $name = PatternScan::first('/\(([a-z][A-Za-z0-9]*(?:[A-Z][A-Za-z0-9]*){2,})\)$/', $description);

            if (isset($name[1])) {
                $declared[$name[1]] = true;
            }
        }
    }

    $names = array_keys($declared);
    sort($names);

    return $cached = $names;
}

// The prefix set is read off those names rather than written down. A guard
// keyed on a list of prefixes cannot see a rival that answers to a different
// one, and this went blind exactly that way: it matched no/pinned/every while
// the most-cited rule in the tree is crossModuleRawTableWrites.
/** @return list<string> */
function docSymbolInvariantPrefixes(): array
{
    $prefixes = [];

    foreach (docSymbolDeclaredInvariants() as $name) {
        $prefix = PatternScan::first('/^[a-z]+/', $name);

        if (isset($prefix[0])) {
            $prefixes[$prefix[0]] = true;
        }
    }

    $found = array_keys($prefixes);
    sort($found);

    return $found;
}

// The capital after the prefix is what keeps `noop`, an English `every` and a
// bare `one` out of a set whose prefixes are otherwise ordinary words.
function docSymbolInvariantPattern(): string
{
    return '/`((?:'.implode('|', array_map(
        static fn (string $prefix): string => preg_quote($prefix, '/'),
        docSymbolInvariantPrefixes(),
    )).')[A-Z][A-Za-z0-9]*)`/';
}

// Every floor here is a positive control for a WALK finding files, and for
// nothing else. The first version counted the invariant names the pages cite,
// which fell as the pages were corrected: twenty-six of those names were the
// phantoms this guard exists to remove, so the number was measuring the defect
// and would have been satisfied by writing prose at a counter.
it('reads the pages and the suite, and no walk comes back empty', function (): void {
    expect(count(docSymbolPages()))->toBeGreaterThan(
        150,
        'The page walk found '.count(docSymbolPages()).' pages, which is too few to have read .docs at all.'
    );

    expect(count(docSymbolSuiteFiles()))->toBeGreaterThan(
        2000,
        'The suite walk found '.count(docSymbolSuiteFiles()).' files. Every name below would read as missing.'
    );

    expect(count(docSymbolsNamed(DOC_SYMBOL_TEST_PATTERN)))->toBeGreaterThan(
        400,
        'The pages cite '.count(docSymbolsNamed(DOC_SYMBOL_TEST_PATTERN)).' test classes, which is too few to '
        .'have read them.'
    );

    expect(count(docSymbolDeclaredInvariants()))->toBeGreaterThan(
        30,
        'The suite declares '.count(docSymbolDeclaredInvariants()).' named arch invariants. The doc-side scan '
        .'takes its prefixes from these, so an empty reading here makes the rule below match nothing at all.'
    );
});

// The doc-side scan is only as wide as its prefixes, and a prefix set typed by
// hand is a rival waiting to happen. Deriving it is what stops that, so the
// derivation is what gets asserted.
it('derives its prefixes from the suite, and covers every name the suite declares', function (): void {
    $prefixes = docSymbolInvariantPrefixes();

    expect(count($prefixes))->toBeGreaterThan(
        5,
        'Read '.count($prefixes).' invariant prefixes off the suite. The scan below would see almost nothing.'
    );

    $unmatched = [];

    foreach (docSymbolDeclaredInvariants() as $name) {
        if (PatternScan::count(docSymbolInvariantPattern(), '`'.$name.'`') !== 1) {
            $unmatched[] = $name;
        }
    }

    expect($unmatched)->toBe([], implode("\n  ", [
        'The suite declares these invariants and the doc-side scan cannot see them, so a page citing one is '
            .'checked by nothing. The prefix set is read off the declared names, so this can only happen if that '
            .'reading broke:',
        ...$unmatched,
    ]));
});

// A test class this repository does not own, named for comparison rather than
// as a claim about this suite, as `class name => why it is a stranger`. Empty,
// and that is the current state of the pages rather than a disabled rule. The
// case below re-checks both halves of every entry, so a name that stops being
// cited and a name that becomes a real file both fail here.
/** @var array<string, string> */
const DOC_TEST_CLASSES_FROM_ELSEWHERE = [];

it('names a test class that exists, everywhere a page cites one', function (): void {
    $files = array_flip(array_map(
        static fn (string $path): string => basename($path, '.php'),
        docSymbolSuiteFiles(),
    ));

    $missing = [];

    foreach (docSymbolsNamed(DOC_SYMBOL_TEST_PATTERN) as $name => $pages) {
        if (array_key_exists($name, $files) || array_key_exists($name, DOC_TEST_CLASSES_FROM_ELSEWHERE)) {
            continue;
        }

        $missing[] = $name.' — named in '.implode(', ', $pages);
    }

    expect($missing)->toBe([], implode("\n  ", [
        'These pages cite a test class no file answers to. A citation is how a reader checks a claim, and one '
            .'that resolves to nothing sends them looking for a guard that was renamed or never written. Point at '
            .'the real file, or say what actually covers it:',
        ...$missing,
    ]));
});

// The exemption above is the only way a citation escapes the rule, so it is
// held to both halves of what earned it: a name no page cites excuses nothing,
// and a name a file now answers to was never a stranger.
it('keeps no stranger a page stopped citing, and none the suite has since grown', function (): void {
    $cited = docSymbolsNamed(DOC_SYMBOL_TEST_PATTERN);
    $files = array_flip(array_map(
        static fn (string $path): string => basename($path, '.php'),
        docSymbolSuiteFiles(),
    ));

    $stale = [];

    foreach (DOC_TEST_CLASSES_FROM_ELSEWHERE as $name => $reason) {
        if (! array_key_exists($name, $cited)) {
            $stale[] = $name.' is exempt because "'.$reason.'", and no page cites it any more';
        }

        if (array_key_exists($name, $files)) {
            $stale[] = $name.' is exempt as a class this repository does not own, and a file now answers to it';
        }
    }

    expect($stale)->toBe([], implode("\n  ", [
        'An exemption that excuses nothing reads as a decision somebody made about this tree, and the next reader',
        'trusts it. Delete the entry:',
        ...$stale,
    ]));
});

it('names an arch invariant the suite declares, everywhere a page cites one', function (): void {
    $suite = '';

    foreach (docSymbolSuiteFiles() as $path) {
        $suite .= (string) file_get_contents($path);
    }

    $missing = [];

    foreach (docSymbolsNamed(docSymbolInvariantPattern()) as $name => $pages) {
        if (str_contains($suite, $name)) {
            continue;
        }

        $missing[] = $name.' — named in '.implode(', ', $pages);
    }

    expect($missing)->toBe([], implode("\n  ", [
        'These pages name an arch invariant that appears nowhere in the suite. Each sentence promises a reviewer '
            .'that the build catches a class of mistake, and the build does not know the name. Rename it to the '
            .'guard that exists, describe the guard that does exist instead, or say plainly that nothing enforces '
            .'this yet — the one thing a page must not do is claim a gate that is not there:',
        ...$missing,
    ]));
});

// Both verdicts are read off one list each, and a list a broken scan built is
// empty for the wrong reason. These plant each miss against the reader.
it('finds a name that resolves to nothing, and passes one that resolves', function (): void {
    expect(PatternScan::all(DOC_SYMBOL_TEST_PATTERN, 'see `tests/Arch/CounterpartiesBoundaryTest.php` for it')[1])
        ->toBe(['CounterpartiesBoundaryTest'], 'a test cited by path went unread');

    expect(PatternScan::all(DOC_SYMBOL_TEST_PATTERN, 'held by `BoundaryArchTest`')[1])
        ->toBe(['BoundaryArchTest'], 'a test cited by bare class name went unread');

    expect(PatternScan::all(DOC_SYMBOL_TEST_PATTERN, 'held by BoundaryArchTest')[1])
        ->toBe([], 'a bare word in prose was read as a citation');

    expect(PatternScan::all(docSymbolInvariantPattern(), 'the `noOtherCardStatementStateMutator` invariant')[1])
        ->toBe(['noOtherCardStatementStateMutator'], 'a named invariant went unread');

    expect(PatternScan::all(docSymbolInvariantPattern(), 'held by `crossModuleRawTableWrites`')[1])
        ->toBe(['crossModuleRawTableWrites'], 'an invariant outside the no/pinned/every shape went unread');

    expect(PatternScan::all(docSymbolInvariantPattern(), 'a `noop` and `$pinnedCount` and `everything`')[1])
        ->toBe([], 'an ordinary word was read as an invariant name');

    expect(docSymbolInvariantPrefixes())->toContain('cross')->toContain('only')->toContain('one');
});
