<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-dependency-reached-from-nine-places
 */

// The runtime the application is written in, rather than a dependency it
// chose: you cannot wrap what you extend or what the container hands you.
//
// Every entry has to be reached by the walk below, and four were not: `Pest`,
// `PHPUnit`, `Termwind` and `Flux` excused nothing anywhere in this tree —
// Flux is written in Blade and never as a PHP namespace, Termwind was named
// only by this list, and neither test runner appears under its own namespace.
// An exemption nobody reaches reads as considered, which is how the next
// package to answer to one of those prefixes inherits the excuse.
/**
 * @return array<string, string> namespace prefix => why it is the runtime
 */
function thirdPartyRuntimeLayer(): array
{
    return [
        'Illuminate' => 'Laravel itself.',
        'Livewire' => 'The component runtime every page is written against.',
        'Carbon' => 'The date type the framework hands back and takes in.',
        'Psr' => 'Interop interfaces — the abstraction, not an implementation.',
        'Symfony' => 'Laravel is built on it; its types surface in framework signatures.',
        'Laravel' => 'First-party Laravel packages (Fortify, Horizon, Prompts).',
        'Nwidart' => 'The module system this repo is structured by.',
        'Spatie\LaravelData' => 'A DTO base class ~120 DTOs extend. You cannot wrap a parent.',
        'Mockery' => 'The test runner.',
    ];
}

/**
 * @return array<string, list<string>> namespace prefix => the one place each package may appear
 */
function thirdPartySeams(): array
{
    return [
        'Amp' => ['Modules/Sync/Internal/Transport', 'Modules/Mobile/Internal/Sync'],
        'BaconQrCode' => ['Modules/Sync/Internal/Pairing'],
        'Beatrax\BiometricVault' => ['Modules/Mobile/Internal/Identity'],
        'Brick' => ['Modules/Ledger/Public/ValueObjects'],
        'Doctrine\SqlFormatter' => ['Modules/DevMode/Internal/Sql'],
        'Dompdf' => ['Modules/Tax/Internal/Services/TaxPdfRenderer.php'],
        'Firebase\JWT' => ['Modules/OpenBanking/Internal/Adapters/EnableBanking'],
        'Genkgo\Camt' => ['Modules/Ingestion/Internal/Adapters/Banking'],
        'Google' => ['Modules/EmailScan/Internal/Clients'],
        'GuzzleHttp' => ['Modules/EmailScan/Internal/Clients', 'Modules/OpenBanking/Internal/Adapters/EnableBanking'],
        'Iban\Validation' => ['Modules/Counterparties/Internal/Resolver'],
        'League\Csv' => [
            'Modules/Ingestion/Internal/Adapters',
            'Modules/Migration/Internal/Parsers',
            'Modules/Reports/Internal/Services/ReportCsvExporter.php',
            'Modules/Tax/Internal/Services/TaxCsvExporter.php',
        ],
        'League\OAuth2' => ['Modules/EmailScan/Internal/OAuth'],
        // moneyphp/money is not a second money library by choice: genkgo/camt
        // returns its objects, so the CAMT adapter unwraps them to minor units
        // in the same file it parses in.
        'Money' => ['Modules/Ingestion/Internal/Adapters/Banking'],
        'Monolog' => ['Modules/DevMode/Internal/Logging'],
        'Native\Mobile' => ['Modules/Mobile/Internal'],
        'NativePHP' => ['Modules/Mobile/Internal'],
        'PhpParser' => ['app/PhpStan'],
        'PHPStan' => ['app/PhpStan'],
        'Ramsey\Uuid' => ['Modules/Sync/Internal/Identity'],
        // Same seam as Amp, and for the same reason: the event loop is what a
        // long-running daemon schedules on, and DaemonTicker is the one place
        // that has to know it.
        'Revolt' => ['Modules/Sync/Internal/Transport'],
        // Two PDF readers in one seam, not one too many: Spatie\PdfToText
        // shells out to poppler's pdftotext, which the phone does not ship, so
        // the pure-PHP parser is the only path a device has to a statement.
        'Smalot\PdfParser' => ['Modules/Ingestion/Internal/Adapters/Ics'],
        'Spatie\Activitylog' => ['Modules/DevMode/Internal/Audit'],
        'Spatie\PdfToText' => ['Modules/Ingestion/Internal/Adapters/Ics'],
        'TheNetworg' => ['Modules/EmailScan/Internal/OAuth'],
        'Webauthn' => ['Modules/Auth/Internal/Lock'],
        'ZBateson\MailMimeParser' => [
            'Modules/EmailScan/Internal/MimeHeaderParser.php',
            'Modules/Receipts/Public/Pipeline/EmlMimeReader.php',
        ],
    ];
}

// A test that pins a wrapper against the library it wraps has a reason to name
// the package; one that merely does arithmetic does not.
/**
 * @return array<string, list<string>> namespace prefix => repo-relative test files
 */
function thirdPartyTestSeams(): array
{
    return [
        // The one test that exists to show what brick/math costs when a rate
        // reaches it as a float — the reason Money wraps it at all.
        'Brick' => ['Modules/Ledger/tests/Feature/FxRatePrecisionTest.php'],
    ];
}

// Two rules over one namespace would drift apart, so this one defers to
// BoundaryArchTest — and the assertion at the foot of this file fails if that
// rule ever disappears.
const THIRD_PARTY_DELEGATED = [
    'Native\Desktop' => 'BoundaryArchTest::noNativePhpImportsOutsideDesktopModule',
];

/** @return list<string> namespace prefixes composer installed, longest first */
function thirdPartyInstalledPrefixes(): array
{
    $manifest = base_path('vendor/composer/installed.json');
    /** @var array{packages?: list<array{autoload?: array{'psr-4'?: array<string, mixed>, 'psr-0'?: array<string, mixed>}}>} $decoded */
    $decoded = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);

    $prefixes = [];
    foreach ($decoded['packages'] ?? [] as $package) {
        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach (array_keys($package['autoload'][$standard] ?? []) as $namespace) {
                $trimmed = trim((string) $namespace, '\\');

                // laravel/pint ships an `App\` psr-4 root of its own, which
                // would otherwise make every first-party class third-party.
                if ($trimmed !== '' && ! in_array(explode('\\', $trimmed)[0], ['App', 'Modules', 'Tests', 'Database'], true)) {
                    $prefixes[] = $trimmed;
                }
            }
        }
    }

    // Mobile-only packages are absent from a desktop vendor/ install, so the
    // rule would silently stop covering them. Naming them keeps it binding.
    $prefixes = array_merge($prefixes, ['Native\Desktop', 'Native\Mobile', 'NativePHP', 'Beatrax\BiometricVault']);
    $prefixes = array_values(array_unique($prefixes));
    usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $prefixes;
}

/** @return list<string> repo-relative paths of PHP and Blade files under $root */
function thirdPartySourceFiles(string $root, bool $wantTests): array
{
    $absolute = base_path($root);
    if (! is_dir($absolute)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }
        // Generated, and generated from the very manifests this rule reads: the
        // compiled package and service manifests name every installed package
        // by namespace, so a walk that opened them would report the whole
        // vendor directory as reaching past its seam.
        if (str_contains($path, '/bootstrap/cache/')) {
            continue;
        }
        if (str_contains($path, '/tests/') !== $wantTests) {
            continue;
        }
        $files[] = str_replace(base_path().'/', '', $path);
    }

    sort($files);

    return $files;
}

/**
 * @param  list<string>  $installed
 * @return list<string> the installed prefixes this file names, comments stripped
 */
function thirdPartyPrefixesIn(string $relativePath, array $installed): array
{
    return thirdPartyPrefixesInSource((string) file_get_contents(base_path($relativePath)), $installed);
}

/**
 * @param  list<string>  $installed  longest first
 * @return list<string>
 */
function thirdPartyPrefixesInSource(string $source, array $installed): array
{
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

    // A separator is only a separator when an identifier follows it. Without
    // that, the escape in a "NativePHP\'s own transport" test title reads as a
    // namespace, and a class name escaped for JavaScript splits into one bogus
    // prefix per segment.
    $found = [];
    $matches = PatternScan::all('/\\\\?\b([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*)\\\\(?=[A-Za-z_])/', $stripped);

    foreach ($matches[1] as $referenced) {
        foreach ($installed as $prefix) {
            if ($referenced === $prefix || str_starts_with($referenced, $prefix.'\\')) {
                $found[$prefix] = true;
                break;
            }
        }
    }

    return array_keys($found);
}

/** @return string the longest listed prefix covering $namespace, or '' when none does */
function thirdPartyOwnerOf(string $namespace): string
{
    $candidates = array_merge(
        array_keys(thirdPartyRuntimeLayer()),
        array_keys(thirdPartySeams()),
        array_keys(THIRD_PARTY_DELEGATED),
    );
    usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($candidates as $candidate) {
        if ($namespace === $candidate || str_starts_with($namespace, $candidate.'\\')) {
            return $candidate;
        }
    }

    return '';
}

/** @return string the module a repo-relative path belongs to, or 'app' outside Modules/ */
function thirdPartyModuleOf(string $relativePath): string
{
    return preg_match('#^Modules/([^/]+)/#', $relativePath, $m) === 1 ? $m[1] : 'app';
}

// Wiring is not domain code: a provider binds a package into the container and
// a command boots a daemon. Both may drive a package their module already
// contains — never introduce one it does not.
function thirdPartyIsModuleEntrypoint(string $relativePath): bool
{
    return str_contains($relativePath, '/Providers/')
        || str_ends_with($relativePath, 'ServiceProvider.php')
        || str_ends_with($relativePath, 'Command.php');
}

// Named files, not the two directories they sit in. "bootstrap/ and
// app/Providers/ are the composition root" excused eleven files to buy one:
// across both directories exactly one names a package with a seam, and every
// file added to either would have inherited the excuse without a diff.
/**
 * @return array<string, array{reason: string, proves: string}>
 */
function thirdPartyCompositionRoots(): array
{
    return [
        'app/Providers/NativeServiceProvider.php' => [
            'reason' => 'NativePHP\'s published plugin-registration stub: its plugins() return type names the mobile plugin provider classes verbatim per the vendor contract, and those packages install only under mobile-app/vendor',
            'proves' => '/function plugins\(\)/',
        ],
    ];
}

function thirdPartyIsCompositionRoot(string $relativePath): bool
{
    return array_key_exists($relativePath, thirdPartyCompositionRoots());
}

it('reaches every third-party package through a seam of ours', function (): void {
    $installed = thirdPartyInstalledPrefixes();
    $seams = thirdPartySeams();
    $offenders = [];
    $walked = 0;

    // 249 installed prefixes and 6,692 production files today, both floored far
    // under. An empty manifest read makes every file name no package at all,
    // and a walk that lost a root reports the same clean tree a clean tree
    // reports — with nothing else in the output looking wrong.
    expect(count($installed))->toBeGreaterThan(50, 'almost no package namespaces were read out of the composer manifest — the read is broken, not the install.');

    foreach (['app', 'Modules', 'bootstrap', 'routes'] as $root) {
        foreach (thirdPartySourceFiles($root, wantTests: false) as $file) {
            $walked++;

            if (thirdPartyIsCompositionRoot($file)) {
                continue;
            }

            foreach (thirdPartyPrefixesIn($file, $installed) as $namespace) {
                $owner = thirdPartyOwnerOf($namespace);

                if ($owner === '' || isset(THIRD_PARTY_DELEGATED[$owner])) {
                    if ($owner === '') {
                        $offenders[] = $file.' — '.$namespace.' names no seam at all';
                    }

                    continue;
                }

                if (! isset($seams[$owner])) {
                    continue;
                }

                $declared = $seams[$owner];
                foreach ($declared as $seam) {
                    if ($file === $seam || str_starts_with($file, $seam.'/')) {
                        continue 2;
                    }
                }

                $sameModule = array_filter(
                    $declared,
                    static fn (string $seam): bool => thirdPartyModuleOf($seam) === thirdPartyModuleOf($file),
                );

                if (thirdPartyIsModuleEntrypoint($file) && $sameModule !== []) {
                    continue;
                }

                $offenders[] = $file.' — '.$owner.' belongs in '.implode(' or ', $declared);
            }
        }
    }

    sort($offenders);

    expect($walked)->toBeGreaterThan(2000, 'the production walk read almost nothing — the roots are wrong, not the tree.');

    expect($offenders)->toBe(
        [],
        "A third-party package may only be named from the seam that owns it —\n".
        "an adapter, a client, a wrapper. Move the call behind that seam and\n".
        "give the caller one of our own types, or, for a package with no seam\n".
        "yet, add one and name it in thirdPartySeams(). Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('never calls a view, a Livewire component or a model a seam', function (): void {
    $offenders = [];

    foreach (thirdPartySeams() as $package => $seams) {
        foreach ($seams as $seam) {
            if (preg_match('#/(Resources/views|Http/Livewire|Models)(/|$)#', $seam) === 1) {
                $offenders[] = $package.' => '.$seam;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "Presentation and persistence are where a dependency spreads from, so\n".
        "neither can be the place that contains it. Put the package behind an\n".
        "adapter and let the view render our own type. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('declares no seam that does not exist or is not used', function (): void {
    $installed = thirdPartyInstalledPrefixes();
    $used = [];

    foreach (['app', 'Modules', 'bootstrap', 'routes'] as $root) {
        foreach (thirdPartySourceFiles($root, wantTests: false) as $file) {
            foreach (thirdPartyPrefixesIn($file, $installed) as $namespace) {
                $owner = thirdPartyOwnerOf($namespace);
                if ($owner !== '') {
                    $used[$owner][] = $file;
                }
            }
        }
    }

    $stale = [];

    foreach (thirdPartyTestSeams() as $package => $seams) {
        foreach ($seams as $seam) {
            if (! file_exists(base_path($seam))) {
                $stale[] = $package.' => '.$seam.' (no such path)';
            }
        }
    }

    foreach (thirdPartySeams() as $package => $seams) {
        foreach ($seams as $seam) {
            if (! file_exists(base_path($seam))) {
                $stale[] = $package.' => '.$seam.' (no such path)';

                continue;
            }

            $covers = array_filter(
                $used[$package] ?? [],
                static fn (string $file): bool => $file === $seam || str_starts_with($file, $seam.'/'),
            );

            if ($covers === []) {
                $stale[] = $package.' => '.$seam.' (nothing there uses it)';
            }
        }
    }

    expect($stale)->toBe(
        [],
        "A seam nobody uses is padding, and padding is how a containment map\n".
        "turns into an exemption list. Delete the entry — or the dependency.\n".
        "Offenders:\n  ".implode("\n  ", $stale),
    );
});

it('gives a package at most one seam per module', function (): void {
    $offenders = [];

    foreach (thirdPartySeams() as $package => $seams) {
        $byModule = [];
        foreach ($seams as $seam) {
            $byModule[thirdPartyModuleOf($seam)][] = $seam;
        }

        foreach ($byModule as $module => $inModule) {
            if (count($inModule) > 1) {
                $offenders[] = $package.' has '.count($inModule).' seams in '.$module.': '.implode(', ', $inModule);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "One module, one place that knows the package. A second entry is not a\n".
        "second seam, it is the scattering this rule exists to stop — widen the\n".
        "first seam or move the code into it. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('lets a module test reach only for a package its own module contains', function (): void {
    $installed = thirdPartyInstalledPrefixes();
    $seams = thirdPartySeams();
    $offenders = [];

    foreach (thirdPartySourceFiles('Modules', wantTests: true) as $file) {
        $module = thirdPartyModuleOf($file);

        foreach (thirdPartyPrefixesIn($file, $installed) as $namespace) {
            $owner = thirdPartyOwnerOf($namespace);

            if ($owner === '' || ! isset($seams[$owner])) {
                continue;
            }

            $testSeams = thirdPartyTestSeams()[$owner] ?? null;

            if ($testSeams !== null) {
                if (! in_array($file, $testSeams, true)) {
                    $offenders[] = $file.' — '.$owner.' is pinned to '.implode(' or ', $testSeams);
                }

                continue;
            }

            $sameModule = array_filter(
                $seams[$owner],
                static fn (string $seam): bool => thirdPartyModuleOf($seam) === $module,
            );

            if ($sameModule === []) {
                $offenders[] = $file.' — '.$owner.' is contained in '.implode(' or ', $seams[$owner]);
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A test outside the containing module reaches past the seam the module\n".
        "under test is supposed to go through, and pins the package's API in\n".
        "place of ours. Assert on our type instead. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('still has the Native\\Desktop rule this one defers to', function (): void {
    $boundary = (string) file_get_contents(base_path('tests/Contracts/BoundaryArchTest.php'));
    $missing = [];

    foreach (THIRD_PARTY_DELEGATED as $package => $rule) {
        $testName = substr($rule, (int) strrpos($rule, ':') + 1);

        if (! str_contains($boundary, $testName)) {
            $missing[] = $package.' => '.$rule;
        }
    }

    expect($missing)->toBe(
        [],
        "This file defers a namespace to a rule that no longer exists, so the\n".
        "namespace is now governed by nothing. Restore the rule or bring the\n".
        "package back into thirdPartySeams(). Offenders:\n  ".implode("\n  ", $missing),
    );
});

it('declares no runtime-layer namespace that nothing in this repository names', function (): void {
    $installed = thirdPartyInstalledPrefixes();
    $reached = [];

    // Production plus the module suites, because two of these entries are
    // reached only from a test: Mockery by seven, Nwidart by the one that
    // asserts the phone's module wiring. A production-only count would read
    // both as excusing nothing and delete two correct entries.
    foreach ([['app', false], ['Modules', false], ['bootstrap', false], ['routes', false], ['Modules', true]] as [$root, $wantTests]) {
        foreach (thirdPartySourceFiles($root, wantTests: $wantTests) as $file) {
            foreach (thirdPartyPrefixesIn($file, $installed) as $namespace) {
                $owner = thirdPartyOwnerOf($namespace);

                if ($owner !== '') {
                    $reached[$owner] = true;
                }
            }
        }
    }

    $excusingNothing = array_values(array_filter(
        array_keys(thirdPartyRuntimeLayer()),
        static fn (string $prefix): bool => ! isset($reached[$prefix]),
    ));

    expect($excusingNothing)->toBe(
        [],
        "A namespace declared as the runtime is named by nothing in this repository, so it\n".
        "excuses nothing and reads as considered to everybody after it — which is how the next\n".
        "package answering to that prefix inherits the excuse. Delete the entry, or the\n".
        "dependency. The seam map is held to the same rule two cases up. Offenders:\n  ".
        implode("\n  ", $excusingNothing),
    );
});

it('reads a package named inline as well as imported, and no package out of an escaped quote', function (): void {
    $installed = ['GuzzleHttp', 'Native\Mobile', 'Illuminate'];

    $planted = <<<'PHP'
        <?php
        use GuzzleHttp\Client;

        final class PlantedReach
        {
            public function run(): void
            {
                $native = \Native\Mobile\Facades\Dialog::class;
                $title = 'NativePHP\'s own transport';
                $escaped = 'App\\\\Models\\\\User';
            }
        }
        PHP;

    expect(thirdPartyPrefixesInSource($planted, $installed))->toBe(
        ['GuzzleHttp', 'Native\Mobile'],
        'The reader must see the import and the inline fully-qualified reference — a `use` scan '
        .'alone is blind to the second, which is how a package reaches a file nothing declared it '
        .'in — and must read neither an escaped quote nor an escaped separator as a namespace.',
    );

    expect(thirdPartyPrefixesInSource("<?php\n// GuzzleHttp\\Client is what this comment is about.\n", $installed))->toBe(
        [],
        'A package named in prose reads as a call site, so a comment explaining a seam would be an offender against it.',
    );
});

it('still holds each composition root to the reason it was granted for', function (): void {
    $installed = thirdPartyInstalledPrefixes();
    $seams = thirdPartySeams();
    $stale = [];

    foreach (thirdPartyCompositionRoots() as $relative => $pin) {
        if (! is_file(base_path($relative))) {
            $stale[] = $relative.' — no such file';

            continue;
        }

        if (! PatternScan::matches($pin['proves'], (string) file_get_contents(base_path($relative)))) {
            $stale[] = $relative.' — no longer reads as "'.$pin['reason'].'"';

            continue;
        }

        // The other half: a composition root that has stopped naming a seamed
        // package outside its seam is excusing nothing, and an exemption that
        // excuses nothing is the next file's inherited excuse.
        $excuses = false;

        foreach (thirdPartyPrefixesIn($relative, $installed) as $namespace) {
            $owner = thirdPartyOwnerOf($namespace);

            if ($owner === '' || ! isset($seams[$owner])) {
                continue;
            }

            foreach ($seams[$owner] as $seam) {
                if ($relative === $seam || str_starts_with($relative, $seam.'/')) {
                    continue 2;
                }
            }

            $excuses = true;
            break;
        }

        if (! $excuses) {
            $stale[] = $relative.' — names no package outside its seam, so the entry excuses nothing';
        }
    }

    expect($stale)->toBe(
        [],
        "A composition root is pinned here and no longer earns it. The pin is a hole in the rule\n".
        "two cases up, so it lasts exactly as long as the reason does — repoint it, or delete it:\n  ".
        implode("\n  ", $stale),
    );
});
