<?php

declare(strict_types=1);

// Arch tests powered by pest-plugin-arch. Empty module skeletons trivially
// satisfy these rules; once subsequent plans add real code the assertions bind.

arch('Modules\\Ledger\\Internal is only used inside Modules\\Ledger')
    ->expect('Modules\\Ledger\\Internal')
    ->toOnlyBeUsedIn('Modules\\Ledger');

arch('Modules\\Core\\Internal is only used inside Modules\\Core')
    ->expect('Modules\\Core\\Internal')
    ->toOnlyBeUsedIn('Modules\\Core');

arch('Modules\\Ingestion\\Internal is only used inside Modules\\Ingestion')
    ->expect('Modules\\Ingestion\\Internal')
    ->toOnlyBeUsedIn('Modules\\Ingestion');

arch('Modules\\Import\\Internal is only used inside Modules\\Import')
    ->expect('Modules\\Import\\Internal')
    ->toOnlyBeUsedIn('Modules\\Import');

arch('Modules\\Categorization\\Internal is only used inside Modules\\Categorization')
    ->expect('Modules\\Categorization\\Internal')
    ->toOnlyBeUsedIn('Modules\\Categorization');

arch('Modules\\Transfers\\Internal is only used inside Modules\\Transfers')
    ->expect('Modules\\Transfers\\Internal')
    ->toOnlyBeUsedIn('Modules\\Transfers');

arch('Modules\\Chains\\Internal is only used inside Modules\\Chains')
    ->expect('Modules\\Chains\\Internal')
    ->toOnlyBeUsedIn('Modules\\Chains');

arch('no Laravel facade usage in module code')
    ->expect('Illuminate\\Support\\Facades')
    ->not->toBeUsedIn('Modules')
    ->ignoring([
        // Carve-out: Laravel's queue infrastructure calls uniqueVia() at
        // queue-push time before the chain-resolution job's constructor
        // DI completes, so a constructor-injected Cache repository is
        // not an option. The Cache facade is the only permitted facade
        // use in module code; ResolveChainLinksJob documents this on
        // its class-level docblock when it ships.
        'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob',
    ]);

arch('Money\\Money types stay inside the ASN adapter folder')
    ->expect('Money\\Money')
    ->toOnlyBeUsedIn('Modules\\Ingestion\\Internal\\Adapters\\Asn');

arch('RederiveFingerprintsCommand is never imported by any HTTP or routing namespace')
    ->expect('Modules\\Ledger\\Internal\\Console\\RederiveFingerprintsCommand')
    ->not->toBeUsedIn([
        'Modules\\Ledger\\Internal\\Http',
        'Modules\\Ledger\\Public\\Http',
        'Modules\\Ledger\\Routes',
        'Modules\\Core\\Internal\\Http',
        'Modules\\Core\\Public\\Http',
        'Modules\\Ingestion\\Internal\\Http',
        'Modules\\Ingestion\\Public\\Http',
        'Modules\\Import\\Internal\\Http',
        'Modules\\Import\\Public\\Http',
        'Modules\\Categorization\\Internal\\Http',
        'Modules\\Categorization\\Public\\Http',
    ]);

it('does not allow any file under Modules/Chains/Internal/Resolvers/ to mutate the transactions table (noResolverWritesTransactions)', function (): void {
    // The Phase 5 chain resolver writes chain_links rows ONLY — it must
    // never UPDATE / INSERT / DELETE on the transactions table. The
    // single architectural exception (a derived funding_account_id on a
    // future RecurringSeries row) lives outside the resolvers directory
    // and outside this phase. The grep strips comments first so legitimate
    // PHPDoc references stay legal.
    $hits = [];
    $resolversDir = base_path('Modules/Chains/Internal/Resolvers');
    if (! is_dir($resolversDir)) {
        // Resolvers folder lands in a later wave; until it does the rule
        // is trivially satisfied.
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $resolversDir,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('/\.php$/', $path) !== 1) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match('/Transaction::query|Transaction::where/', $stripped) === 1
            || preg_match("/->table\(['\"]transactions['\"]\)[^;]*->(update|insert|delete)\\s*\\(/", $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Resolver files must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file other than CardStatementStateMachine to mutate card_statements.state (noOtherCardStatementStateMutator)', function (): void {
    // Phase 5 D-95 invariant: only the CardStatementStateMachine class
    // may transition card_statements.state. Other resolver code reads
    // the lifecycle but never writes the state column.
    $hits = [];
    $chainsDir = base_path('Modules/Chains');
    if (! is_dir($chainsDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $allowedFile = base_path('Modules/Chains/Internal/CardStatementStateMachine.php');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $chainsDir,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if ($path === $allowedFile) {
            continue;
        }
        if (preg_match('/\.php$/', $path) !== 1) {
            continue;
        }
        if (str_contains($path, '/tests/')) {
            // Test files synthesise card_statements rows directly via
            // factories or raw inserts; the architectural invariant is a
            // production-code rule.
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match("/->table\(['\"]card_statements['\"]\)[^;]*->update\\s*\\(\\s*\\[\\s*['\"]state['\"]/", $stripped) === 1
            || preg_match('/CardStatement::query\(\)[^;]*->update\(\s*\[\s*[\'"]state[\'"]/', $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Only CardStatementStateMachine may mutate card_statements.state. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow a paypal-api route or class to exist (noPaypalApiRoute)', function (): void {
    // Defensive arch invariant: the PayPal Reporting API integration is
    // deferred behind a business-account upgrade trigger. If a future task
    // accidentally lands a paypal-api adapter, a Reporting API client class,
    // or a route segment using `paypal-api`, this test fails loudly. The
    // grep strips `/* ... */` and `// ...` comments first so legitimate
    // PHPDoc references stay legal.
    $hits = [];

    foreach (['routes', 'Modules'] as $root) {
        $absoluteRoot = base_path($root);
        if (! is_dir($absoluteRoot)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $absoluteRoot,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('/\.(php|blade\.php)$/', $path) !== 1) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            if (preg_match('/PaypalApiAdapter|PaypalReportingApi|paypal-api/i', $stripped) === 1) {
                $hits[] = $path;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "No paypal-api route or class should exist. Found in:\n  ".implode("\n  ", $hits)
    );
});
