<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// TranslationParityArchTest measures en against every locale in both
// directions, so a line carried by all twenty-six files is in parity by
// construction whether or not a screen renders it. This asks the question
// parity cannot: does any code reach the key.
// @link ../../.docs/conventions/a-translated-line-has-a-call-site.md

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function translatedLineRepoRoot(): string
{
    return dirname((string) realpath(base_path('Modules')));
}

/** @return array<string, string> module directory name => the namespace its provider registers */
function translatedLineNamespaces(): array
{
    $namespaces = [];
    foreach (glob(translatedLineRepoRoot().'/Modules/*/Providers/*.php') ?: [] as $provider) {
        $source = (string) file_get_contents($provider);
        if (preg_match('/loadModuleResources\(\s*\'([a-z0-9\-]+)\'/', $source, $found) !== 1) {
            continue;
        }
        if (preg_match('#/Modules/([^/]+)/#', $provider, $module) === 1) {
            $namespaces[$module[1]] = $found[1];
        }
    }

    return $namespaces;
}

/** @return list<string> modules shipping English lines under a namespace no provider registers */
function translatedLineUnregisteredModules(): array
{
    $namespaces = translatedLineNamespaces();
    $orphans = [];

    foreach (glob(translatedLineRepoRoot().'/Modules/*/Resources/lang/en/*.php') ?: [] as $file) {
        if (preg_match('#/Modules/([^/]+)/Resources/lang/en/#', $file, $found) !== 1) {
            continue;
        }

        if (! isset($namespaces[$found[1]])) {
            $orphans[$found[1]] = true;
        }
    }

    $named = array_keys($orphans);
    sort($named);

    return $named;
}

/**
 * @param  array<array-key, mixed>  $lines
 * @return list<string>
 */
function translatedLineKeyPaths(array $lines, string $prefix = ''): array
{
    $paths = [];
    foreach ($lines as $name => $line) {
        $path = $prefix === '' ? (string) $name : $prefix.'.'.$name;
        $paths = is_array($line)
            ? array_merge($paths, translatedLineKeyPaths($line, $path))
            : array_merge($paths, [$path]);
    }

    return $paths;
}

/** @return array<string, string> fully qualified en key => the file that declares it */
function translatedLineDeclaredKeys(): array
{
    $namespaces = translatedLineNamespaces();

    $declared = [];
    foreach (glob(translatedLineRepoRoot().'/Modules/*/Resources/lang/en/*.php') ?: [] as $file) {
        if (preg_match('#/Modules/([^/]+)/Resources/lang/en/([^/]+)\.php#', $file, $found) !== 1) {
            continue;
        }
        // A module registering no namespace has no key to build, and the rule
        // below cannot name a line it cannot spell. That is a defect in its
        // own right rather than an exemption, so it is reported separately
        // instead of skipped quietly here.
        if (! isset($namespaces[$found[1]])) {
            continue;
        }

        $lines = require $file;
        if (! is_array($lines)) {
            continue;
        }

        $rel = str_replace(translatedLineRepoRoot().'/', '', $file);
        foreach (translatedLineKeyPaths($lines) as $path) {
            $declared[$namespaces[$found[1]].'::'.$found[2].'.'.$path] = $rel;
        }
    }

    return $declared;
}

/**
 * @return list<string> the files that can put a line in front of a reader
 */
function translatedLineSourceFiles(): array
{
    $root = translatedLineRepoRoot();

    $files = [];
    foreach (['Modules', 'app', 'resources', 'routes', 'config', 'database'] as $dir) {
        if (! is_dir($root.'/'.$dir)) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            $path = $file->getPathname();
            if (preg_match('/\.(php|js|ts|json)$/', $path) !== 1) {
                continue;
            }
            // A lang file is the declaration, not a reference to itself; a test
            // asserting a key does not put it on a screen.
            if (str_contains($path, '/Resources/lang/') || str_contains($path, '/tests/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

const TRANSLATED_LINE_KEY = '[a-z][a-z0-9\-]*::[a-z0-9_\-]+\.[A-Za-z0-9_.\-]*';
const TRANSLATED_LINE_GROUP = '[a-z][a-z0-9\-]*::[a-z0-9_\-]+';

/**
 * @return array{exact: array<string, true>, prefixes: array<string, true>}
 */
function translatedLineReferencesIn(string $source): array
{
    $exact = [];
    $prefixes = [];

    // A whole literal is that key — and, when it names a branch rather than
    // a leaf, the subtree under it: labelKey('anomaly::alerts.reasons')
    // appends the leaf inside the enum, where no literal key exists.
    foreach (PatternScan::all('/[\'"]('.TRANSLATED_LINE_KEY.')[\'"]/', $source)[1] as $key) {
        $exact[$key] = true;
        $prefixes[rtrim($key, '.').'.'] = true;
    }

    // A literal the code then concatenates onto, or opens an interpolation
    // in, is a prefix: 'recurring::fixed_payments.empty_'.$arm reaches
    // empty_all and empty_this_month without spelling either.
    foreach (PatternScan::all('/[\'"]('.TRANSLATED_LINE_KEY.')[\'"]\s*\./', $source)[1] as $key) {
        $prefixes[$key] = true;
    }

    foreach (PatternScan::all('/"('.TRANSLATED_LINE_KEY.')(?=[{$])/', $source)[1] as $key) {
        $prefixes[$key] = true;
    }

    // A group named without any key under it is read whole, by Lang::group
    // or by a helper that appends: labelKey('recurring::review').
    foreach (PatternScan::all('/[\'"]('.TRANSLATED_LINE_GROUP.')[\'"]/', $source)[1] as $group) {
        $prefixes[$group.'.'] = true;
    }

    return ['exact' => $exact, 'prefixes' => $prefixes];
}

/**
 * Every key path production code can reach, as a set of exact keys and a set of
 * prefixes covering the subtrees it reaches without naming a leaf.
 *
 * @return array{exact: array<string, true>, prefixes: array<string, true>}
 */
function translatedLineReferences(): array
{
    $exact = [];
    $prefixes = [];

    foreach (translatedLineSourceFiles() as $path) {
        $found = translatedLineReferencesIn((string) file_get_contents($path));

        $exact += $found['exact'];
        $prefixes += $found['prefixes'];
    }

    return ['exact' => $exact, 'prefixes' => $prefixes];
}

// A module whose provider registers no namespace ships lines with no key to
// resolve them by. The rule below cannot report those lines, because it cannot
// spell them — so the silence is reported here rather than inside the walk.
it('registers a lang namespace for every module that ships English lines', function (): void {
    expect(translatedLineUnregisteredModules())->toBe([], implode("\n", [
        'These modules ship Resources/lang/en files and their provider calls no loadModuleResources(),',
        'so nothing resolves the lines and the rule below cannot name them:',
        ...translatedLineUnregisteredModules(),
    ]));
});

it('has a call site for every line it asks twenty-six translators to carry', function (): void {
    $declared = translatedLineDeclaredKeys();
    $files = translatedLineSourceFiles();

    // Three denominators before the verdict: the keys, the files the callers
    // are read from, and the callers themselves. Each of them empty makes the
    // offender list below empty for a reason that is not a clean tree. The
    // floors sit far under today's 4,040 keys, 2,783 files and 3,869 callers.
    expect(count($declared))->toBeGreaterThan(
        1000,
        'the walk declared '.count($declared).' English keys, which is too few to be this tree.'
    );

    expect(count($files))->toBeGreaterThan(
        1000,
        'the walk opened '.count($files).' source files to read callers from, which is too few to be this tree.'
    );

    ['exact' => $exact, 'prefixes' => $prefixes] = translatedLineReferences();

    expect(count($exact))->toBeGreaterThan(
        1000,
        'the walk found '.count($exact).' referenced keys, which is too few — every declared line would read as unreached.'
    );

    $unreachable = [];
    foreach ($declared as $key => $file) {
        if (isset($exact[$key])) {
            continue;
        }
        foreach (array_keys($prefixes) as $prefix) {
            if (str_starts_with($key, $prefix)) {
                continue 2;
            }
        }
        $unreachable[] = $key.' ('.$file.')';
    }

    sort($unreachable);

    expect($unreachable)->toBe([], "translated lines nothing renders:\n  ".implode("\n  ", $unreachable));
});

// The verdict is a list that is empty over a tree where every line is reached
// and empty over a reader that stopped reading, so the reader is driven against
// planted sources. Each of the four shapes below was a real call site the first
// draft reported as absent.
it('reads a key spelled whole, one concatenated onto, one interpolated, and a group read whole', function (): void {
    $whole = translatedLineReferencesIn('<?php Lang::get(\'ledger::transactions.title\');');
    expect($whole['exact'])->toHaveKey('ledger::transactions.title')
        ->and($whole['prefixes'])->toHaveKey('ledger::transactions.title.');

    $joined = translatedLineReferencesIn('<?php Lang::get(\'recurring::fixed_payments.empty_\'.$arm);');
    expect($joined['prefixes'])->toHaveKey('recurring::fixed_payments.empty_');

    $interpolated = translatedLineReferencesIn('<?php $k = "anomaly::alerts.reason_{$case->value}";');
    expect($interpolated['prefixes'])->toHaveKey('anomaly::alerts.reason_');

    $group = translatedLineReferencesIn('<?php labelKey(\'recurring::review\');');
    expect($group['prefixes'])->toHaveKey('recurring::review.');

    $none = translatedLineReferencesIn('<?php $x = \'not a key at all\';');
    expect($none['exact'])->toBe([])
        ->and($none['prefixes'])->toBe([]);
});

it('flattens a nested lang array to the key paths a caller spells', function (): void {
    expect(translatedLineKeyPaths(['a' => ['b' => 'one', 'c' => ['d' => 'two']], 'e' => 'three']))
        ->toBe(['a.b', 'a.c.d', 'e']);
});
