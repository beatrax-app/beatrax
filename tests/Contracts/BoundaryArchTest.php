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

arch('Modules\\EmailScan\\Internal is only used inside Modules\\EmailScan')
    ->expect('Modules\\EmailScan\\Internal')
    ->toOnlyBeUsedIn('Modules\\EmailScan');

arch('Modules\\Receipts\\Internal is only used inside Modules\\Receipts')
    ->expect('Modules\\Receipts\\Internal')
    ->toOnlyBeUsedIn('Modules\\Receipts');

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
        // Same carve-out applies to the three EmailScan queued jobs:
        // backfill, incremental scan, and discovery scan all call
        // Cache::driver('redis') from uniqueVia() so the per-inbox /
        // per-user single-flight lock fires before the constructor DI
        // completes. The job class docblocks repeat this rationale
        // when each lands in later plans.
        'Modules\\EmailScan\\Internal\\Jobs\\BackfillInboxJob',
        'Modules\\EmailScan\\Internal\\Jobs\\IncrementalScanJob',
        'Modules\\EmailScan\\Internal\\Jobs\\DiscoveryScanJob',
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

it('does not allow any file under Modules/EmailScan/ to mutate the transactions table (noTransactionWritesFromEmailScan)', function (): void {
    // Phase 6 / Phase 7 architectural boundary: the EmailScan module
    // owns the fetch + persist .eml + index pipeline only. Transitions
    // out of `inbox_messages.status='fetched'` and any write against
    // the transactions table belong to the matcher phase. This rule
    // mirrors the resolver-side noResolverWritesTransactions invariant
    // (Chains module) for the EmailScan module's subtree. The grep
    // strips block + line comments first so legitimate PHPDoc
    // references stay legal.
    $hits = [];
    $emailScanDir = base_path('Modules/EmailScan');
    if (! is_dir($emailScanDir)) {
        // Module folder lands in a later wave; until it does the rule
        // is trivially satisfied.
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $emailScanDir,
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
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match('/Transaction::query|Transaction::where|Transaction::create/', $stripped) === 1
            || preg_match("/->table\(['\"]transactions['\"]\)[^;]*->(update|insert|delete)\\s*\\(/", $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Modules/EmailScan/ must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file other than InboxScanStateMachine to mutate inbox_scan_state (noOtherInboxScanStateMutator)', function (): void {
    // Only the InboxScanStateMachine class may transition the per-inbox
    // scan-state row. Other module code reads the row but never writes
    // it. Migrations are exempt because they seed initial rows + the
    // schema itself. The grep targets the `->update(...)` shape on the
    // table so insertOrIgnore + migration inserts stay legal; the
    // pattern catches both the simple stub variant (lands in an early
    // plan) and the lockForUpdate-bearing variant (lands later) since
    // both surfaces still go through the table-builder ->update() call.
    $hits = [];
    $emailScanDir = base_path('Modules/EmailScan');
    if (! is_dir($emailScanDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $allowedFile = base_path('Modules/EmailScan/Internal/InboxScanStateMachine.php');
    $migrationsDir = base_path('Modules/EmailScan/Database/Migrations');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $emailScanDir,
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
            continue;
        }
        if (str_starts_with($path, $migrationsDir.DIRECTORY_SEPARATOR)) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match("/->table\(['\"]inbox_scan_state['\"]\)[^;]*->update\\s*\\(/", $stripped) === 1
            || preg_match('/InboxScanState::query\(\)[^;]*->update\(/', $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Only InboxScanStateMachine may mutate inbox_scan_state. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file other than InboxScanStateMachine to write inboxes.backfill_progress (noOtherBackfillProgressMutator)', function (): void {
    // backfill_progress is technically owned by the inboxes table,
    // not inbox_scan_state, but it is functionally a per-inbox
    // lifecycle signal — the /inboxes Blade reads it the same way it
    // reads inbox_scan_state.status. Routing the column through
    // InboxScanStateMachine::recordBackfillProgress keeps the
    // sole-mutator invariant intact across the whole per-inbox
    // lifecycle surface. OAuthCallbackController inserts the inbox
    // row pair on first connect (which is a CREATE, not a lifecycle
    // mutation) so the grep targets the UPDATE shape specifically.
    $hits = [];
    $emailScanDir = base_path('Modules/EmailScan');
    if (! is_dir($emailScanDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $allowedFile = base_path('Modules/EmailScan/Internal/InboxScanStateMachine.php');
    $migrationsDir = base_path('Modules/EmailScan/Database/Migrations');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $emailScanDir,
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
            continue;
        }
        if (str_starts_with($path, $migrationsDir.DIRECTORY_SEPARATOR)) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        // The grep looks for the literal column name appearing
        // inside an update() argument list on the inboxes table.
        // Matches both raw query-builder and Eloquent shapes.
        if (preg_match("/->table\(['\"]inboxes['\"]\)[^;]*->update\\s*\\([^)]*backfill_progress/", $stripped) === 1
            || preg_match("/Inbox::query\(\)[^;]*->update\\s*\\([^)]*backfill_progress/", $stripped) === 1) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Only InboxScanStateMachine may write inboxes.backfill_progress. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file under Modules/Receipts/ to import EmailScan OAuth/client symbols (noEmailFetchFromReceipts)', function (): void {
    // Phase 7 architectural boundary: the Receipts module owns the
    // matcher pipeline + .eml/.mbox ingestion + ParsedReceiptDto →
    // SourceTransactionDto bridge only. Email fetch / OAuth /
    // provider-client work belongs to the EmailScan module
    // (Phase 6). The receipts module reads `InboxMessageQuery` +
    // the on-disk .eml — it must never import Gmail/Graph clients
    // or OAuth providers. This rule mirrors the Phase 6
    // `noTransactionWritesFromEmailScan` invariant by flipping the
    // direction. The grep strips block + line comments first so
    // legitimate PHPDoc references stay legal, and `tests/` is
    // excluded so test doubles + fake fixtures can name the
    // forbidden symbols.
    $hits = [];
    $receiptsDir = base_path('Modules/Receipts');
    if (! is_dir($receiptsDir)) {
        // Module folder lands in a later wave; until it does the
        // rule is trivially satisfied.
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $receiptsDir,
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
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match(
                '/GmailApiClient|GraphApiClientContract|GoogleOAuthProvider|MicrosoftOAuthProvider|OAuthStateRepository|OAuthSecretsRepository/',
                $stripped,
            ) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Modules/Receipts/ must never import EmailScan OAuth/client symbols. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any Modules/EmailScan migration to declare an OAuth-secret column (noOAuthTokensInEmailScanSchema)', function (): void {
    // Baseline invariant: OAuth client secrets and refresh tokens
    // live exclusively in the chmod-600 JSON repository on disk; no
    // EmailScan migration may add a column named refresh_token /
    // client_secret / access_token (case-insensitive). The grep
    // strips block + line comments so legitimate PHPDoc references
    // stay legal. Trivially satisfied while no EmailScan migrations
    // exist, and a guard rail for the migration plans that follow.
    $hits = [];
    $migrationsDir = base_path('Modules/EmailScan/Database/Migrations');
    if (! is_dir($migrationsDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $migrationsDir,
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
        if (preg_match('/refresh_token|client_secret|access_token/i', $stripped) === 1) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "No EmailScan migration may declare an OAuth-secret column. Offenders:\n  ".implode("\n  ", $hits),
    );
});
