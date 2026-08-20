<?php

declare(strict_types=1);

/*
 * A third-party package is reached through one seam of ours, not from
 * wherever it happens to be convenient.
 *
 * The repo already believes this in places — Money wraps brick/money,
 * EnableBankingHttpClient wraps Guzzle, Camt053Adapter wraps genkgo/camt —
 * but nothing held it, so brick/money leaked into an FX service, an import
 * pipeline, a merge resolver and a Blade view. A dependency reached from
 * nine places cannot be upgraded, substituted or reasoned about; reached
 * from one, it can.
 *
 * The map below is the whole rule: every package the shipped tree touches
 * names the seam that owns it. It is not an exemption list, and four
 * assertions stop it becoming one — a seam must exist, must be used, may
 * never be a view/Livewire component/model, and a package may have at most
 * one seam per module. Padding the map to excuse a violation fails one of
 * them; the only way to go green is to move the code.
 */

/**
 * The runtime the application is written in, rather than a dependency it
 * chose. You cannot wrap what you extend or what the container hands you.
 *
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
        'Pest' => 'The test runner.',
        'PHPUnit' => 'The test runner.',
        'Mockery' => 'The test runner.',
        'Termwind' => 'Console rendering, resolved by the framework.',
        'Flux' => 'The Livewire component library the views are written in.',
    ];
}

/**
 * Every other package, and the one place each is allowed to appear.
 *
 * @return array<string, list<string>> namespace prefix => repo-relative seams
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

/**
 * Where a test may drive the package itself, tighter than the module rule
 * below allows. A test that pins a wrapper against the library it wraps has
 * a reason to name it; one that merely does arithmetic does not.
 *
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

/**
 * Native\Desktop already has a tighter, reviewed rule of its own in
 * BoundaryArchTest, with a named carve-out for the Community shell. Two
 * rules over one namespace would drift apart, so this one defers — and the
 * assertion at the foot of this file fails if that rule ever disappears.
 */
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
    $source = (string) file_get_contents(base_path($relativePath));
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

    $found = [];
    if (preg_match_all('/\\\\?\b([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*)\\\\/', $stripped, $matches) === false) {
        return [];
    }

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

/**
 * Wiring is not domain code: a module's service provider binds a package
 * into the container and a console command boots a daemon. Both may drive a
 * package their module already contains — never introduce one it does not.
 */
function thirdPartyIsModuleEntrypoint(string $relativePath): bool
{
    return str_contains($relativePath, '/Providers/')
        || str_ends_with($relativePath, 'ServiceProvider.php')
        || str_ends_with($relativePath, 'Command.php');
}

// bootstrap/ and app/Providers/ are the application's composition root: their
// whole job is naming the packages the container assembles. Nothing else in
// app/ gets this.
function thirdPartyIsCompositionRoot(string $relativePath): bool
{
    return str_starts_with($relativePath, 'bootstrap/') || str_starts_with($relativePath, 'app/Providers/');
}

it('reaches every third-party package through a seam of ours', function (): void {
    $installed = thirdPartyInstalledPrefixes();
    $seams = thirdPartySeams();
    $offenders = [];

    foreach (['app', 'Modules', 'bootstrap', 'routes'] as $root) {
        foreach (thirdPartySourceFiles($root, wantTests: false) as $file) {
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
