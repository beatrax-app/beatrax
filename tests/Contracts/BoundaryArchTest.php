<?php

declare(strict_types=1);
use Illuminate\Routing\Route;
use Symfony\Component\Yaml\Yaml;

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

arch('Modules\\Recurring\\Internal is only used inside Modules\\Recurring')
    ->expect('Modules\\Recurring\\Internal')
    ->toOnlyBeUsedIn('Modules\\Recurring');

arch('Modules\\DriftAlerts\\Internal is only used inside Modules\\DriftAlerts')
    ->expect('Modules\\DriftAlerts\\Internal')
    ->toOnlyBeUsedIn('Modules\\DriftAlerts');

arch('Modules\\Anomaly\\Internal is only used inside Modules\\Anomaly')
    ->expect('Modules\\Anomaly\\Internal')
    ->toOnlyBeUsedIn('Modules\\Anomaly');

arch('Modules\\Desktop\\Internal is only used inside Modules\\Desktop')
    ->expect('Modules\\Desktop\\Internal')
    ->toOnlyBeUsedIn('Modules\\Desktop');

arch('Modules\\Mobile\\Internal is only used inside Modules\\Mobile')
    ->expect('Modules\\Mobile\\Internal')
    ->toOnlyBeUsedIn('Modules\\Mobile');

arch('Modules\\Onboarding\\Internal is only used inside Modules\\Onboarding')
    ->expect('Modules\\Onboarding\\Internal')
    ->toOnlyBeUsedIn('Modules\\Onboarding');

arch('Modules\\Community\\Internal is only used inside Modules\\Community')
    ->expect('Modules\\Community\\Internal')
    ->toOnlyBeUsedIn('Modules\\Community');

arch('Modules\\Counterparties\\Internal is only used inside Modules\\Counterparties')
    ->expect('Modules\\Counterparties\\Internal')
    ->toOnlyBeUsedIn('Modules\\Counterparties');

// The Sync device-identity tests (Phase 12) must prime the Auth session
// lock state to exercise real crypto — Modules/Sync/tests/TestCase.php calls
// LockStateManager->unlock() in setUp(). There is deliberately no Public
// unlock seam (AppLockKeyService exposes only release()/withhold() by design,
// LOCK-04), so this single test-infrastructure base class is an explicit,
// reviewed exemption. Scoped to the one class so any PRODUCTION Sync code
// reaching into Auth\Internal still fails this rule.
//
// Modules\Sync\Tests\Support\EnablesEncryptionForUser (Phase 14.1) is the
// same shape of exemption: every 14.1 encryption regression test primes the
// KEK via this shared trait, which also calls LockStateManager->unlock()
// directly for the identical reason (no Public unlock seam). Added
// alongside the TestCase exemption above rather than replacing it, since
// both are real, distinct test-infrastructure classes that need it.
arch('Modules\\Auth\\Internal is only used inside Modules\\Auth')
    ->expect('Modules\\Auth\\Internal')
    ->toOnlyBeUsedIn('Modules\\Auth')
    ->ignoring([
        'Modules\\Sync\\Tests\\TestCase',
        'Modules\\Sync\\Tests\\Support\\EnablesEncryptionForUser',
    ]);

arch('Modules\\DevMode\\Internal is only used inside Modules\\DevMode')
    ->expect('Modules\\DevMode\\Internal')
    ->toOnlyBeUsedIn('Modules\\DevMode');

// Module Route files (`Modules/*/Routes/web.php`) are the canonical
// Laravel surface where `Illuminate\Support\Facades\Route` is used as
// a static DSL entry point. The framework's route loader executes
// these files as closures rather than classes, so there is no
// constructor-injection seam for the Route binding. Pest's
// `expect()->not->toBeUsedIn(...)` walks namespace-classified files;
// `Routes/web.php` files are anonymous closures under
// `Modules\<Name>\Routes` and naturally fall outside the class
// boundary the check enforces. This carve-out is therefore implicit
// in `pest-plugin-arch`'s file-walk semantics — recorded here so a
// future contributor adding a class-based router shape (e.g. a
// `Modules\Foo\Routes\WebRoutes` class) knows the Route facade is
// part of the documented Laravel-convention allow-list and not a
// bug.
arch('no Laravel facade usage in module code')
    ->expect('Illuminate\\Support\\Facades')
    ->not->toBeUsedIn('Modules')
    ->ignoring([
        // Single carve-out: the shared LockStore helper is the only
        // module file permitted to use the Cache facade (and the
        // config() helper). Laravel's queue infrastructure invokes
        // ShouldBeUniqueUntilProcessing::uniqueVia() at queue-push time,
        // before the job's constructor DI completes, so a constructor-
        // injected Cache repository is not reachable from a uniqueVia()
        // body. Every ShouldBeUnique* job's uniqueVia() returns
        // LockStore::forUniqueJobs(), so the facade crossing is confined
        // to this one file. The phpstan.neon ignoreErrors list mirrors
        // this allow-list.
        'Modules\\Core\\Public\\Support\\LockStore',
        // Native-chrome carve-out: NativePHP's window, app-menu,
        // OS-theme, and notification API is only reachable through its
        // facades, which NativePHP invokes outside the container
        // lifecycle — there is no constructor-injection seam for them.
        // The crossing is confined to the desktop module's native-chrome
        // classes: the NativeAppServiceProvider NativePHP boots, the
        // AppMenuBuilder it delegates application-menu composition to,
        // the OsThemeProbe that wraps `System::theme()` behind the
        // `OsThemeSignal` Public contract, and the
        // `DispatchOsNotification` listener that calls
        // `Notification::title()->message()->event()->reference()
        // ->show()` (the D-12 / D-13 / D-14 dispatcher — the
        // `WindowFocusState` collaborator and the `UrlGenerator`
        // come through constructor DI; only the facade chain itself
        // is unavoidable). The phpstan.neon ignoreErrors list
        // mirrors this allow-list.
        //
        // The macOS menu-bar tray (D-09) is intentionally NOT in this
        // list — the persistent tray is created in the Electron main
        // process via the `nativephp_inject_persistent_tray` prebuild
        // patch (see `NativeAppServiceProvider` docblock), so no PHP
        // module code needs the `MenuBar` facade.
        'Modules\\Desktop\\Internal\\NativeAppServiceProvider',
        'Modules\\Desktop\\Internal\\Native\\AppMenuBuilder',
        'Modules\\Desktop\\Internal\\Native\\OsThemeProbe',
        'Modules\\Desktop\\Internal\\Listeners\\DispatchOsNotification',
        // SurfaceWorkerCrashAlert calls Notification::title()->...->show()
        // for the D-07 OS notification when the window is unfocused.
        // The other four collaborators (Clock, DatabaseManager,
        // WindowFocusState, UrlGenerator) come through constructor DI;
        // only the Notification facade chain itself is unavoidable.
        'Modules\\Desktop\\Internal\\Listeners\\SurfaceWorkerCrashAlert',
        // NavigateOnNotificationDeepLink calls Window::current()->url($route)
        // for the D-14 notification-click deep-link. The Window facade
        // is the only path NativePHP exposes for navigating a focused
        // window; no constructor-injection seam exists.
        'Modules\\Desktop\\Internal\\Listeners\\NavigateOnNotificationDeepLink',
        // ApplyCloseWindowChoice calls App::quit() and
        // Window::current()->hide() for the D-08 close-action JS glue
        // (deferred from plan 15-03). Both facades are canonical
        // NativePHP API shapes with no constructor-injection seam.
        'Modules\\Desktop\\Internal\\Listeners\\ApplyCloseWindowChoice',
    ]);

arch('Money\\Money types stay inside the bank-statement adapter folder')
    ->expect('Money\\Money')
    ->toOnlyBeUsedIn('Modules\\Ingestion\\Internal\\Adapters\\Banking');

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

arch('SeriesDetector implementors are never imported by Modules\\Recurring\\Internal\\Http (noSynchronousDetectionInRequestLifecycle)')
    ->expect('Modules\\Recurring\\Public\\Contracts\\SeriesDetector')
    ->not->toBeUsedIn([
        'Modules\\Recurring\\Internal\\Http',
        'Modules\\Recurring\\Resources',
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

it('does not allow any file under Modules/Recurring/ to mutate the transactions table (noTransactionWritesFromRecurring)', function (): void {
    // Phase 8 architectural boundary: the Recurring module is analytical-
    // only. Transaction-type ownership stays with the Phase 4 income
    // detector. Mirrors the EmailScan noTransactionWritesFromEmailScan
    // invariant: the grep strips block + line comments first so legitimate
    // PHPDoc references stay legal, and `tests/` is excluded so test
    // factories can populate transactions directly.
    $hits = [];
    $recurringDir = base_path('Modules/Recurring');
    if (! is_dir($recurringDir)) {
        // Module folder lands in a later wave; until it does the rule
        // is trivially satisfied.
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $recurringDir,
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
        "Modules/Recurring/ must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file other than RecurringSeriesStateMachine to mutate recurring_series.state (noOtherRecurringSeriesStateMutator)', function (): void {
    // Only the RecurringSeriesStateMachine class may transition the
    // per-series state column. Other module code reads the row, and
    // may UPDATE non-state columns (metric refresh — latest amount,
    // monthly equivalent, next-expected-charge, funding-chain link)
    // without going through the state machine; the grep targets the
    // `state` column specifically so metric-refresh updates plus
    // INSERTs into recurring_series_occurrences stay legal.
    // Migrations are exempt because they seed initial rows + the
    // schema itself.
    $hits = [];
    $recurringDir = base_path('Modules/Recurring');
    if (! is_dir($recurringDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $allowedFile = base_path('Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php');
    $migrationsDir = base_path('Modules/Recurring/Database/Migrations');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $recurringDir,
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
            preg_match("/->table\(['\"]recurring_series['\"]\)[^;]*->update\\s*\\(\\s*\\[\\s*['\"]state['\"]/", $stripped) === 1
            || preg_match('/RecurringSeries::query\(\)[^;]*->update\(\s*\[\s*[\'"]state[\'"]/', $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Only RecurringSeriesStateMachine may mutate recurring_series.state. Offenders:\n  ".implode("\n  ", $hits),
    );
});

arch('Modules\\Recurring\\Internal is never imported from Modules\\DriftAlerts (crossModuleAccessGoesThroughPublic)')
    ->expect('Modules\\Recurring\\Internal')
    ->not->toBeUsedIn('Modules\\DriftAlerts');

arch('DriftEvaluator is never imported by Modules\\DriftAlerts\\Internal\\Http (noSynchronousDriftDetectionInRequestLifecycle)')
    ->expect('Modules\\DriftAlerts\\Internal\\DriftEvaluator')
    ->not->toBeUsedIn([
        'Modules\\DriftAlerts\\Internal\\Http',
        'Modules\\DriftAlerts\\Resources',
    ]);

it('does not allow any file under Modules/DriftAlerts/ to mutate the recurring_series table (noRecurringSeriesWritesFromDriftAlerts)', function (): void {
    // Architectural boundary: the DriftAlerts module is analytical-
    // only. recurring_series mutations stay with the Recurring
    // module's state machine and Public Actions. The grep targets
    // WRITE verbs (update / insert / delete) only — cross-module
    // SELECTs are funnelled through Recurring's Public service
    // surface elsewhere; the rule here just guarantees DriftAlerts
    // never writes. Mirrors the Recurring noTransactionWritesFromRecurring
    // shape: strips block + line comments first so legitimate PHPDoc
    // references stay legal, and `tests/` is excluded so test
    // factories can populate recurring_series rows directly.
    $hits = [];
    $driftDir = base_path('Modules/DriftAlerts');
    if (! is_dir($driftDir)) {
        // Module folder lands in a later wave; until it does the rule
        // is trivially satisfied.
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $driftDir,
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
            preg_match('/RecurringSeries::query|RecurringSeries::where|RecurringSeries::create|RecurringSeries::firstOrCreate|RecurringSeries::updateOrCreate/', $stripped) === 1
            || preg_match("/->table\(['\"]recurring_series['\"]\)[^;]*->(update|insert|delete|truncate)\\s*\\(/", $stripped) === 1
            || preg_match("/->update\(['\"]recurring_series['\"]/", $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Modules/DriftAlerts/ must not mutate the recurring_series table. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file other than DriftAlertStateMachine to mutate drift_alerts.state (noOtherDriftAlertStateMutator)', function (): void {
    // Only the DriftAlertStateMachine class may transition the per-
    // alert state column. Other module code reads the row, and may
    // UPDATE non-state columns (snoozed_until, actioned_at) without
    // going through the state machine; the grep targets the `state`
    // column specifically so metric-refresh updates plus INSERTs into
    // drift_alert_transitions stay legal. Migrations are exempt
    // because they seed initial rows + the schema itself.
    $hits = [];
    $driftDir = base_path('Modules/DriftAlerts');
    if (! is_dir($driftDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $allowedFile = base_path('Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php');
    $migrationsDir = base_path('Modules/DriftAlerts/Database/Migrations');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $driftDir,
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
            preg_match("/->table\(['\"]drift_alerts['\"]\)[^;]*->update\\s*\\(\\s*\\[\\s*['\"]state['\"]/", $stripped) === 1
            || preg_match('/DriftAlert::query\(\)[^;]*->update\(\s*\[\s*[\'"]state[\'"]/', $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Only DriftAlertStateMachine may mutate drift_alerts.state. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file other than AnomalyAlertStateMachine to mutate anomaly_alerts.state (noOtherAnomalyAlertStateMutator)', function (): void {
    // Only the AnomalyAlertStateMachine class may transition the per-
    // alert state column. Other module code reads the row, and may
    // UPDATE non-state companion columns (snoozed_until, actioned_at,
    // dismissed_as) without going through the state machine; the grep
    // targets the `state` column specifically so companion-column updates
    // plus INSERTs into anomaly_alert_transitions stay legal. Migrations
    // are exempt because they seed the schema + the trigger pair itself.
    $hits = [];
    $anomalyDir = base_path('Modules/Anomaly');
    if (! is_dir($anomalyDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $allowedFile = base_path('Modules/Anomaly/Internal/StateMachines/AnomalyAlertStateMachine.php');
    $migrationsDir = base_path('Modules/Anomaly/Database/Migrations');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $anomalyDir,
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
            preg_match("/->table\(['\"]anomaly_alerts['\"]\)[^;]*->update\\s*\\(\\s*\\[\\s*['\"]state['\"]/", $stripped) === 1
            || preg_match('/AnomalyAlert::query\(\)[^;]*->update\(\s*\[\s*[\'"]state[\'"]/', $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Only AnomalyAlertStateMachine may mutate anomaly_alerts.state. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file under Modules/Anomaly/ to mutate the transactions table (noTransactionWritesFromAnomaly)', function (): void {
    // Phase 9 architectural boundary: the Anomaly module is read-only
    // against the ledger. It evaluates transactions to flag unusual
    // charges but never writes them — transaction-type ownership stays
    // with the Phase 4 income detector / Ledger. Mirrors the Recurring
    // noTransactionWritesFromRecurring invariant: the grep strips block +
    // line comments first so legitimate PHPDoc references stay legal, and
    // `tests/` is excluded so test factories can populate transactions
    // directly. Reads (Transaction::query()->where()->get()) stay allowed.
    $hits = [];
    $anomalyDir = base_path('Modules/Anomaly');
    if (! is_dir($anomalyDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $anomalyDir,
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
            preg_match("/->table\(['\"]transactions['\"]\)[^;]*->(update|insert|delete)\\s*\\(/", $stripped) === 1
            || preg_match('/Transaction::create\s*\(/', $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Modules/Anomaly/ must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits),
    );
});

arch('Modules\\Forecasting\\Internal is only used inside Modules\\Forecasting (crossModuleAccessGoesThroughPublic)')
    ->expect('Modules\\Forecasting\\Internal')
    ->toOnlyBeUsedIn('Modules\\Forecasting');

arch('Modules\\Search\\Internal is only used inside Modules\\Search')
    ->expect('Modules\\Search\\Internal')
    ->toOnlyBeUsedIn('Modules\\Search');

arch('ProjectionPipeline is never imported by Modules\\Forecasting\\Internal\\Http (noSynchronousForecastingInRequestLifecycle)')
    ->expect('Modules\\Forecasting\\Internal\\Pipeline\\ProjectionPipeline')
    ->not->toBeUsedIn([
        'Modules\\Forecasting\\Internal\\Http',
        'Modules\\Forecasting\\Resources',
    ]);

it('does not allow any file under Modules/Forecasting/ to mutate transactions / recurring_series / card_statements / chain_links / drift_alerts tables (noTransactionWritesFromForecasting)', function (): void {
    // Forecasting is strictly analytical: it reads the upstream
    // substrate but never writes to it. The five forbidden tables
    // cover the transaction surface (Phase 1/3), the recurring-series
    // surface (Phase 8), the card-statement surface (Phase 5), the
    // chain-routing surface (Phase 5), and the drift-alert surface
    // (Phase 9). Reads (Transaction::query()->where(...) etc.) are
    // permitted — only mutating verbs (update / insert / delete /
    // truncate) and the Eloquent class-level write methods (::create,
    // ::firstOrCreate, ::updateOrCreate) are caught. The grep strips
    // block + line comments first so legitimate PHPDoc references
    // stay legal, and `tests/` is excluded so test factories can
    // populate substrate rows directly.
    $hits = [];
    $forecastingDir = base_path('Modules/Forecasting');
    if (! is_dir($forecastingDir)) {
        // Module folder lands in a later wave; until it does the rule
        // is trivially satisfied.
        expect(true)->toBeTrue();

        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $forecastingDir,
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
        if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match('/Transaction::query|Transaction::where|Transaction::create|RecurringSeries::query|RecurringSeries::create|RecurringSeries::firstOrCreate|RecurringSeries::updateOrCreate|CardStatement::query|CardStatement::create|ChainLink::query|ChainLink::create|DriftAlert::query|DriftAlert::create/', $stripped) === 1
            || preg_match("/->table\\(['\"](transactions|recurring_series|card_statements|chain_links|drift_alerts)['\"]\\)[^;]*->(update|insert|delete|truncate)\\s*\\(/", $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "Modules/Forecasting must not write to transactions / recurring_series / card_statements / chain_links / drift_alerts. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow any file to JOIN forecast_scenario_mutations onto transactions / recurring_series_occurrences / chain_links / card_statements (noScenarioMutationsJoinedToTransactionQueries)', function (): void {
    // The single most load-bearing arch invariant of the Forecasting
    // module: forecast_scenario_mutations rows describe hypothetical
    // what-if changes the user has saved into a scenario; they MUST
    // NEVER be JOINed onto the transaction substrate, because doing so
    // would let a scenario silently bleed into a real-money read. The
    // scan walks the ENTIRE Modules/ tree — not just Forecasting —
    // because the failure mode is any future contributor (Forecasting
    // or another module) reaching for a convenience JOIN. The grep
    // strips block + line comments first so legitimate PHPDoc
    // references stay legal, and `tests/` is excluded so test
    // factories + contract suites can synthesise both substrates
    // independently.
    $hits = [];
    $modulesDir = base_path('Modules');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        $hasMutationJoin = preg_match(
            "/->(join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]forecast_scenario_mutations['\"]/",
            $stripped,
        ) === 1;
        $hasForbiddenTable = preg_match(
            "/['\"](transactions|recurring_series_occurrences|chain_links|card_statements)['\"]/",
            $stripped,
        ) === 1;
        if ($hasMutationJoin && $hasForbiddenTable) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "forecast_scenario_mutations must never be JOINed onto transaction-substrate tables. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow system_alerts to be JOINed onto the transactions table (systemAlertsTableNotJoinedToTransactions)', function (): void {
    // Phase 11 invariant: system_alerts is a purely operational surface.
    // It surfaces failure events to the user via the dashboard banner
    // and MUST NEVER be JOINed onto the transactions table. JOINing the
    // operational substrate onto the domain substrate would let a
    // background-job alert bleed into a real-money read, blurring the
    // separation the project relies on for the "calm tool" promise.
    // The scan walks the entire Modules/ tree, strips block and line
    // comments first so PHPDoc references stay legal, and excludes
    // tests/ so fixtures and contract suites can synthesise both
    // substrates independently.
    $hits = [];
    $modulesDir = base_path('Modules');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        $hasJoin = preg_match(
            "/->(join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]system_alerts['\"]/",
            $stripped,
        ) === 1;
        $hasTransactions = preg_match(
            "/['\"]transactions['\"]/",
            $stripped,
        ) === 1;
        if ($hasJoin && $hasTransactions) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe(
        [],
        "system_alerts must never be JOINed onto the transactions table. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow Laravel facades inside Modules/Core/Internal/Console/ (noFacadeCallsFromCoreConsoleCommands)', function (): void {
    // Phase 11 invariant: Core's console commands take their
    // dependencies through constructor DI exclusively. Importing or
    // calling any `Illuminate\Support\Facades\…` class breaks the
    // testability contract — facade-rooted calls cannot be substituted
    // with a mock from the test harness, which kills the artisan-test
    // story for db:backup, db:restore, beatrax:doctor, and
    // beatrax:failed-jobs. The scan walks Modules/Core/Internal/
    // Console/ recursively, strips block + line comments so PHPDoc
    // references stay legal, and any remaining facade-namespace
    // import / FQCN reference adds the file to the failure list.
    $hits = [];
    $consoleDir = base_path('Modules/Core/Internal/Console');
    if (! is_dir($consoleDir)) {
        expect(true)->toBeTrue();

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($consoleDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/Illuminate\\\\Support\\\\Facades\\\\/', $stripped) === 1) {
            $hits[] = $path;
        }
    }

    expect($hits)->toBe(
        [],
        "Modules/Core/Internal/Console/ commands may not import Illuminate\\Support\\Facades\\*. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow Laravel global path / container helpers inside Modules/Core/Internal/Console/ (noLaravelGlobalHelpersInCoreConsoleCommands)', function (): void {
    // Companion to the facades grep above: catches the *_path() and
    // container-helper family that are functionally equivalent to
    // facade calls (they resolve through the singleton Application
    // container) but do not import the `Illuminate\Support\Facades\…`
    // namespace, so the facades scan misses them. CLAUDE.md
    // `feedback_laravel_di_only.md` forbids these helpers across the
    // entire codebase; the scope here mirrors the facade-scan above
    // (Core console commands only) because that is the surface the
    // Phase 11 invariant locks. Other modules carry pre-existing
    // helper usage that is tracked as separate cleanup.
    $bannedFunctions = [
        'base_path',
        'app_path',
        'config_path',
        'database_path',
        'public_path',
        'resource_path',
        'storage_path',
        'app',
        'resolve',
        'config',
        'auth',
        'request',
        'now',
        'today',
    ];
    // Word-boundary on the function-call surface. `\b<name>\s*\(` matches
    // a top-level call without also matching `$obj->base_path(...)` or
    // `MyClass::config(...)` — the negative lookbehind for `->` and `::`
    // rules out the method-call shape.
    $pattern = '/(?<![>:])\\b('.implode('|', array_map('preg_quote', $bannedFunctions)).')\\s*\\(/';

    $hits = [];
    $consoleDir = base_path('Modules/Core/Internal/Console');
    if (! is_dir($consoleDir)) {
        expect(true)->toBeTrue();

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($consoleDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match($pattern, $stripped) === 1) {
            $hits[] = $path;
        }
    }

    expect($hits)->toBe(
        [],
        "Modules/Core/Internal/Console/ commands may not call Laravel global helpers (base_path, app, config, etc). Inject the Application / Repository / etc. instead. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow the Auth facade or auth/session helpers across Modules/* outside the allow-list (noAuthFacadeOrHelper)', function (): void {
    // The current-user identity must travel through constructor-injected
    // collaborators (the CurrentUser contract), never through the global
    // Auth facade, the auth() / session() helpers, or request()->user() /
    // request()->session(). Those forms resolve through the singleton
    // Application container, cannot be substituted from a test harness, and
    // erode the module-boundary contract.
    //
    // The allow-list below is the only sanctioned exception surface: the
    // authentication actions and the Fortify glue genuinely need to drive
    // the guard. It is a per-file precise list — never a glob. Adding a
    // file to it requires editing the array AND a code-review
    // justification; see the Auth module's service-provider docblock for
    // the rationale behind that surface.
    //
    // The scan walks the entire module tree, strips block + line + Blade
    // comments so PHPDoc references such as `@see Auth::user()` and Blade
    // `{{-- ... --}}` notes stay legal, skips test files (test harnesses
    // legitimately call actingAs()) and migration files (anonymous classes
    // outside the rule), and flags any remaining banned symbol.
    $allowList = [
        'Modules/Auth/Public/Actions/LoginAction.php',
        'Modules/Auth/Public/Actions/SignupAction.php',
        'Modules/Auth/Public/Actions/LogoutAction.php',
        'Modules/Auth/Public/Actions/ResetPasswordAction.php',
        'Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php',
        'Modules/Auth/Public/Actions/AddUserAction.php',
        'Modules/Auth/Internal/Fortify/FortifyServiceProvider.php',
        'Modules/Auth/Internal/Fortify/Authenticator.php',
    ];

    // Banned symbols: the Auth facade import + its static lookups, the auth()
    // / session() global helpers, and request()->user() / request()->session().
    // The `(?<![>:])` lookbehind keeps `$this->session(...)` method calls and
    // `SomeClass::session(...)` static calls out of the helper match.
    $bannedPatterns = [
        '/Illuminate\\\\Support\\\\Facades\\\\Auth\b/',
        '/Auth::user\s*\(/',
        '/Auth::id\s*\(/',
        '/Auth::loginUsingId\s*\(/',
        '/(?<![>:])\bauth\s*\(/',
        '/request\s*\(\s*\)\s*->\s*user\s*\(/',
        '/request\s*\(\s*\)\s*->\s*session\s*\(/',
        '/(?<![>:])\bsession\s*\(/',
    ];

    $modulesDir = base_path('Modules');
    if (! is_dir($modulesDir)) {
        expect(true)->toBeTrue();

        return;
    }

    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('#/tests/#', $path) === 1 || preg_match('#/Database/Migrations/#', $path) === 1) {
            continue;
        }
        $relative = str_replace(base_path().'/', '', $path);
        if (in_array($relative, $allowList, true)) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s', '', $contents) ?? $contents;
        foreach ($bannedPatterns as $pattern) {
            if (preg_match($pattern, $stripped) === 1) {
                $hits[] = $relative;
                break;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Modules/* may not call Auth facade / auth helper / request()->user / request()->session / session() helper / Auth::loginUsingId outside the allow-list. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow raw path helpers or hard-coded storage literals outside UserDataPathService (noStoragePathHardCodedOutsideUserDataPathService)', function (): void {
    // PKG-01 / Phase 13 invariant: every filesystem path flows through
    // Modules\Core\Public\Services\UserDataPathService so a NativePHP
    // build can retarget the storage root. The service is the sole
    // sanctioned caller of base_path(); no other production file may
    // call database_path() / storage_path() / base_path() or embed the
    // literals 'database.sqlite' / 'storage/app/'.
    //
    // Scope is three roots — Modules, app, config — because config files
    // call the static accessors directly (the (?<![>:]) lookbehind keeps
    // UserDataPathService::databaseFile( legal). Test files keep the raw
    // helpers: they run in a known local dev environment and never ship.
    // Migration directories are skipped, consistent with
    // noAuthFacadeOrHelper and phpstan.neon — anonymous-class migrations
    // resolve the service through the container separately. The grep
    // strips block + line + Blade comments first so PHPDoc references
    // stay legal, and .blade.php files are exempt from the literal check
    // because user-facing <code> tags legitimately display storage paths.
    //
    // NOTE: this test stays RED until Plan 02 migrates every call site —
    // that is expected and correct; it must not be weakened to pass early.
    $allowList = [
        'Modules/Core/Public/Services/UserDataPathService.php',
    ];

    // Bare function-call shape only — `(?<![>:])` rules out
    // `$obj->storage_path()` / `Class::base_path()` method calls.
    $bannedHelpers = '/(?<![>:])\b(database_path|storage_path|base_path)\s*\(/';
    // Literal strings that hard-code the dev-mode storage layout.
    $bannedLiterals = "/['\"](database\\.sqlite|storage\\/app\\/)/";

    $hits = [];
    foreach (['Modules', 'app', 'config'] as $root) {
        $abs = base_path($root);
        if (! is_dir($abs)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('#/tests/#', $path) === 1
                || preg_match('#/Database/Migrations/#', $path) === 1) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $allowList, true)) {
                continue;
            }
            $isBlade = str_ends_with($path, '.blade.php');
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s', '', $contents) ?? $contents;
            if (preg_match($bannedHelpers, $stripped) === 1
                || (! $isBlade && preg_match($bannedLiterals, $stripped) === 1)) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Raw path helpers / storage literals are forbidden outside UserDataPathService. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow Horizon imports outside the allow-listed provider (noHorizonImportsInShippedBuildCode)', function (): void {
    // PKG-03 invariant: laravel/horizon is a require-dev package and the
    // /horizon dashboard serialises transaction data, so no shipped-build
    // file may reference a `Laravel\Horizon\` namespaced symbol. The sole
    // allow-listed file is App\Providers\HorizonServiceProvider, which
    // extends the package provider and gates the dashboard on dev mode.
    //
    // bootstrap/providers.php legitimately names the Horizon base class
    // inside a class_exists() autoload guard — that guard is the mandated
    // mechanism that keeps a `composer install --no-dev` tree from
    // fataling. The grep strips `class_exists(\Laravel\Horizon\...)`
    // arguments before scanning so the guard does not count as an import,
    // keeping the allow-list at exactly one file. Block + line comments
    // are stripped first so PHPDoc references stay legal, and `/tests/`
    // paths are skipped.
    $allowList = [
        'app/Providers/HorizonServiceProvider.php',
    ];

    $hits = [];
    foreach (['app', 'Modules', 'bootstrap', 'routes'] as $root) {
        $abs = base_path($root);
        if (! is_dir($abs)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/tests/')) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $allowList, true)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            // Drop the class_exists() autoload guard argument: a defensive
            // class_exists(\Laravel\Horizon\...) reference does not load
            // Horizon code and is the sanctioned --no-dev guard.
            $stripped = preg_replace(
                '/class_exists\s*\(\s*\\\\?Laravel\\\\Horizon\\\\[^)]*\)/',
                '',
                $stripped,
            ) ?? $stripped;
            if (preg_match('/Laravel\\\\Horizon\\\\/', $stripped) === 1) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Only app/Providers/HorizonServiceProvider.php may reference Laravel\\Horizon\\* symbols. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow Native\\Desktop imports outside Modules/Desktop (noNativePhpImportsOutsideDesktopModule)', function (): void {
    // Phase 15 containment invariant: nativephp/desktop's API lives under
    // the `Native\Desktop\` namespace, and the desktop shell is quarantined
    // inside the Modules/Desktop module so no other module reaches into
    // NativePHP directly. No shipped-build file outside Modules/Desktop may
    // reference a `Native\Desktop\` namespaced symbol.
    //
    // Allow-list carve-out for Modules/Community: the suggest-mapping flow
    // launches an https://github.com/... Compare URL in the system
    // browser via the OpenExternalUrlAction wrapper. The action validates
    // https + host allow-list BEFORE delegating to the Shell contract,
    // and the NoOpShell fallback is bound when the bundle runs outside
    // the NativePHP runtime. The service provider names the contract FQCN
    // only to inspect prior bindings via `$this->app->bound(...)`. The
    // companion `noShellContractOutsideAllowList` invariant locks the
    // contract-import surface tighter; this rule's allow-list is the
    // wider net for the `Native\Desktop\` namespace as a whole.
    //
    // Block + line comments are stripped first so PHPDoc references stay
    // legal, and `/tests/` paths are skipped. The Modules/Desktop subtree
    // is the sanctioned home and is excluded wholesale.
    $allowList = [
        'Modules/Community/Public/Actions/OpenExternalUrlAction.php',
        'Modules/Community/Internal/Shell/NoOpShell.php',
        'Modules/Community/Providers/CommunityServiceProvider.php',
    ];

    $hits = [];
    foreach (['app', 'Modules', 'bootstrap', 'routes'] as $root) {
        $abs = base_path($root);
        if (! is_dir($abs)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/tests/')) {
                continue;
            }
            if (str_contains($path, '/Modules/Desktop/')) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $allowList, true)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            if (preg_match('/Native\\\\Desktop\\\\/', $stripped) === 1) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Native\\Desktop\\* symbols may only be referenced inside Modules/Desktop. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('does not allow a bg-white / text-slate-900 utility without a dark: companion in a themed module view (darkCompanionUtilitiesOnThemedViews)', function (): void {
    // Phase 15 D-15 invariant: the dark theme is delivered with the
    // Tailwind v4 class strategy — every element carrying a hard-coded
    // light surface utility (`bg-white`, `text-slate-900`) must also
    // carry the matching `dark:` companion so the element renders
    // correctly when the `dark` class is on `<html>`.
    //
    // The guard scans `resources/views` AND every
    // `Modules/*/Resources/views` directory unconditionally — full
    // coverage, no allow-list (Plan 15-07 Task 3 closed the gate
    // after every module was themed). A future view added without a
    // `dark:` companion fails CI.
    //
    // The check is per class-attribute string (the `class="..."` and
    // `@class([...])` shapes a single element carries): when a string
    // contains the `bg-white` token it must also contain a `dark:bg-`
    // utility, and when it contains `text-slate-900` it must also
    // contain a `dark:text-` utility. Tokens are matched on word
    // boundaries so `bg-white/50` and `bg-whitesmoke` are handled
    // correctly and `dark:bg-white` is not mistaken for a violation.

    // Roots to scan: the app-level views, plus each module's views.
    $roots = [];
    $appViews = base_path('resources/views');
    if (is_dir($appViews)) {
        $roots[] = $appViews;
    }
    $modulesDir = base_path('Modules');
    if (is_dir($modulesDir)) {
        foreach (new DirectoryIterator($modulesDir) as $moduleDir) {
            if (! $moduleDir->isDir() || $moduleDir->isDot()) {
                continue;
            }
            $viewsDir = $moduleDir->getPathname().'/Resources/views';
            if (is_dir($viewsDir)) {
                $roots[] = $viewsDir;
            }
        }
    }

    // Extracts every class-attribute string a Blade file declares —
    // both `class="..."` / `class='...'` attributes and `@class([...])`
    // arrays — so the companion check runs per element.
    $classStringsOf = static function (string $contents): array {
        $strings = [];
        if (preg_match_all('/class\s*=\s*"([^"]*)"/', $contents, $m) > 0) {
            foreach ($m[1] as $s) {
                $strings[] = $s;
            }
        }
        if (preg_match_all("/class\s*=\s*'([^']*)'/", $contents, $m) > 0) {
            foreach ($m[1] as $s) {
                $strings[] = $s;
            }
        }
        if (preg_match_all('/@class\s*\(\s*\[(.*?)\]\s*\)/s', $contents, $m) > 0) {
            foreach ($m[1] as $s) {
                $strings[] = $s;
            }
        }

        return $strings;
    };

    $hits = [];
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.blade.php')) {
                continue;
            }
            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);
            // Strip Blade comments so {{-- ... --}} examples stay legal.
            $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents;
            $relative = str_replace(base_path().'/', '', $path);

            foreach ($classStringsOf($stripped) as $classString) {
                // `bg-white` token (not `dark:bg-white`, not `bg-whitesmoke`).
                $hasBgWhite = preg_match('/(?<![:\w-])bg-white(?![\w])/', $classString) === 1;
                if ($hasBgWhite && preg_match('/dark:bg-/', $classString) !== 1) {
                    $hits[] = $relative.' — bg-white without dark:bg- companion';
                }
                // `text-slate-900` token (not `dark:text-slate-900`).
                $hasInkText = preg_match('/(?<![:\w-])text-slate-900(?![\w])/', $classString) === 1;
                if ($hasInkText && preg_match('/dark:text-/', $classString) !== 1) {
                    $hits[] = $relative.' — text-slate-900 without dark:text- companion';
                }
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Every themed-module view element with a bg-white / text-slate-900 utility needs a dark: companion. Offenders:\n  ".implode("\n  ", array_unique($hits)),
    );
});

it('does not allow the impersonation surface to re-appear on disk (impersonationSurfaceRemoved)', function (): void {
    // Containment invariant for the dropped Phase 12 "Act as partner"
    // feature: the four files that drove the guard-swap / banner-paint
    // pipeline must remain absent so a future contributor cannot
    // re-introduce the surface without also editing this invariant.
    //
    // The check is pure filesystem (not class_exists) because
    // class_exists() triggers the Composer autoloader, which may hold a
    // stale entry pointing at a recently-deleted file and emit a
    // misleading "failed to open stream" warning. A direct file_exists
    // call against base_path() is deterministic.
    //
    // A regression here means: someone added back the action / DTO /
    // middleware / Blade partial without also reviewing the security
    // posture trade-off recorded in the deletion commit. Fail loudly.
    $bannedFiles = [
        'Modules/Auth/Public/Actions/ImpersonateUserAction.php',
        'Modules/Auth/Public/Actions/EndImpersonationAction.php',
        'Modules/Auth/Public/Dto/ImpersonationResult.php',
        'Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php',
        'Modules/Auth/Resources/views/partials/impersonation-banner.blade.php',
    ];

    $present = [];
    foreach ($bannedFiles as $relative) {
        if (file_exists(base_path($relative))) {
            $present[] = $relative;
        }
    }

    expect($present)->toBe(
        [],
        "The impersonation surface must remain deleted. Found:\n  ".implode("\n  ", $present),
    );
});

it('does not allow the literal `diederik` / `Diederik` anywhere in Modules / tests / resources / config (noDiederikLiteralAfterRename)', function (): void {
    // Containment invariant for the diederik -> beatrax rename: every
    // production-side literal must be flipped so a future contributor
    // adding a new file does not accidentally re-introduce the old
    // brand name. The case-insensitive grep matches both `diederik`
    // and `Diederik` shapes.
    //
    // Allow-list: this invariant's own file (needs the literal to
    // assert the absence), the regression-guard test that asserts no
    // `diederik:*` artisan signature remains in the kernel, and the
    // sidebar render test that grep-asserts the rendered HTML carries
    // no `diederik` literal post-rename. All three deliberately house
    // the literal `diederik` as their assertion subject.
    $allowList = [
        'tests/Contracts/BoundaryArchTest.php',
        'tests/Feature/BeatraxCommandsResolveTest.php',
        'Modules/Core/tests/Feature/AppSidebarRenderTest.php',
    ];

    $roots = ['Modules', 'tests', 'resources', 'config'];

    $hits = [];
    foreach ($roots as $root) {
        $abs = base_path($root);
        if (! is_dir($abs)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            // Pest snapshot baselines belong to the test infrastructure;
            // they are re-baselined alongside the source rename so the
            // snapshot diff is reviewable. Skipping `.snap` keeps the
            // arch test from double-counting that work.
            if (str_ends_with($path, '.snap')) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $allowList, true)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (preg_match('/diederik/i', $contents) === 1) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Every diederik / Diederik literal must be flipped to beatrax. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('requires every /dev route to apply the ensureDeveloperMode middleware (everyDevModeRouteAppliesEnsureDeveloperModeMiddleware)', function (): void {
    // Architectural invariant: every Dev Console HTTP route MUST
    // apply the `ensureDeveloperMode` middleware alias so the
    // 404-not-403 information-disclosure mitigation covers the
    // entire /dev/* surface. A new /dev/* route registered without
    // the alias would silently disclose the Dev Console's
    // existence to non-developers — this invariant fails CI before
    // that ships.
    //
    // We walk the runtime route table (not the source files) because
    // the alias name only resolves against gatherMiddleware(),
    // which expands group-applied middleware. The URI prefix filter
    // matches both `dev` (the bare overview route) and any uri starting
    // with `dev/` (every panel route). It deliberately excludes URIs
    // like `developer/...` or `develop/...` even though no such route
    // exists today — a precise containment check is cheaper than a
    // future surprise.
    $routes = collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(static fn (Route $r): bool => $r->uri() === 'dev' || str_starts_with($r->uri(), 'dev/'));

    expect($routes)->not->toBeEmpty(
        'No /dev/* routes registered — is DevModeServiceProvider booting?',
    );

    $missing = [];
    foreach ($routes as $route) {
        /** @var Route $route */
        $stack = $route->gatherMiddleware();
        if (! in_array('ensureDeveloperMode', $stack, true)) {
            $missing[] = $route->uri();
        }
    }

    expect($missing)->toBe(
        [],
        "Every /dev/* route MUST apply ensureDeveloperMode. Offenders:\n  ".implode("\n  ", $missing),
    );
});

it('keeps PaymentType-unique string literals inside the PaymentType enum (noPaymentTypeStringLeak)', function (): void {
    // Three PaymentType values — `pin`, `online`, `direct_debit` — are
    // unique to the payment_type column and never legitimately appear
    // elsewhere in the codebase. The invariant scans Modules/ for any
    // production-source-tree appearance of these literals outside the
    // canonical enum file and the Database/Migrations carve-out
    // (where the trigger heredoc enumerates the allowed values inline
    // as part of the SQLite WHEN clause).
    //
    // The other PaymentType values (`transfer`, `cash`, `fee`,
    // `refund`, `unknown`) intentionally collide with the
    // pre-existing `transactions.type` enum, with categorisation
    // slugs, and with PayPal CSV event-type values, so a string scan
    // would flag legitimate domain uses. Callers of those values
    // resolve them through `PaymentType::Transfer`, etc., by convention
    // — the per-hinter unit tests in plan 16.1-02 lock in that
    // convention.
    //
    // Test files freely fixture these values to exercise the migration
    // triggers; the `tests/` exclusion below keeps that legal. The
    // scan strips PHPDoc / single-line comments before matching so a
    // comment mentioning the value never flags as an offender.
    $needles = ['pin', 'online', 'direct_debit'];
    $modulesDir = base_path('Modules');
    if (! is_dir($modulesDir)) {
        expect(true)->toBeTrue();

        return;
    }
    $allowedFile = base_path('Modules/Import/Public/Enums/PaymentType.php');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $offenders = [];
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('/\.php$/', $path) !== 1) {
            continue;
        }
        if ($path === $allowedFile) {
            continue;
        }
        if (str_contains($path, '/tests/')) {
            continue;
        }
        if (str_contains($path, '/Database/Migrations/')) {
            continue;
        }
        // The canonical BIP39 mnemonic word list (used to render the
        // device-pairing safety-number words) legitimately contains the
        // English dictionary word "online" as fixed list data — it is not a
        // PaymentType value or usage. Carve out this single data file.
        if (str_ends_with($path, 'Modules/Sync/Internal/Pairing/Bip39WordList.php')) {
            continue;
        }

        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;

        foreach ($needles as $needle) {
            $singleQuoted = "/(?<![A-Za-z0-9_])'".preg_quote($needle, '/')."'/";
            $doubleQuoted = '/(?<![A-Za-z0-9_])"'.preg_quote($needle, '/').'"/';
            if (preg_match($singleQuoted, $stripped) === 1 || preg_match($doubleQuoted, $stripped) === 1) {
                $offenders[] = $path.' : '.$needle;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "PaymentType-unique string literals leaked outside Modules/Import/Public/Enums/PaymentType.php:\n  "
        .implode("\n  ", $offenders),
    );
});

it('requires every *Hinter class under Modules/Import/Internal/Parsers to implement the PaymentTypeHinter contract (paymentTypeHinterContract)', function (): void {
    // Plan 16.1-02 invariant: every per-source payment-type hinter
    // plus the universal description-keyword fallback under
    // Modules/Import/Internal/Parsers/ is a strategy implementation
    // of the shared `PaymentTypeHinter` contract. The classifier
    // stage's container-tag wiring depends on the contract being
    // uniformly implemented — a class named `*Hinter` that omits the
    // implements clause would silently fail to satisfy the
    // iterable<PaymentTypeHinter> type at the classifier seam.
    //
    // Filesystem walk over Modules/Import/Internal/Parsers, gated on
    // the `*Hinter.php` filename suffix so both the
    // `*PaymentTypeHinter` per-source classes and the
    // `DescriptionKeywordFallbackHinter` are captured. The class FQN
    // is reconstructed from the path so the check works against the
    // shipped classes without booting the framework.
    // ReflectionClass is consulted to resolve the implements list
    // because a literal grep would miss subclasses that inherit the
    // contract.
    $parsersDir = base_path('Modules/Import/Internal/Parsers');
    if (! is_dir($parsersDir)) {
        // Parser tree lands in plan 16.1-02; until it does the rule
        // is trivially satisfied.
        expect(true)->toBeTrue();

        return;
    }

    $contract = 'Modules\\Import\\Public\\Contracts\\PaymentTypeHinter';
    expect(interface_exists($contract))->toBeTrue();

    $offenders = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($parsersDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (! str_ends_with($path, 'Hinter.php')) {
            continue;
        }

        // Derive the FQN from the on-disk path: every parser-tree class
        // lives at Modules/Import/Internal/Parsers/<sub>/Foo.php which
        // maps onto `Modules\\Import\\Internal\\Parsers\\<sub>\\Foo`.
        $relativeFromModules = str_replace(base_path().'/', '', $path);
        $withoutExt = substr($relativeFromModules, 0, -strlen('.php'));
        $fqn = str_replace('/', '\\', $withoutExt);

        if (! class_exists($fqn)) {
            $offenders[] = $relativeFromModules.' — class FQN not autoloadable ('.$fqn.')';

            continue;
        }
        $reflection = new ReflectionClass($fqn);
        if (! $reflection->implementsInterface($contract)) {
            $offenders[] = $relativeFromModules.' — does not implement '.$contract;
        }
    }

    expect($offenders)->toBe(
        [],
        "Every *Hinter class under Modules/Import/Internal/Parsers must implement PaymentTypeHinter. Offenders:\n  ".implode("\n  ", $offenders),
    );
});

it('restricts Native\\Desktop\\Contracts\\Shell imports to the allow-listed action and fallback (noShellContractOutsideAllowList)', function (): void {
    // Plan 16.1-05 invariant: the NativePHP Shell contract is the
    // sole outbound system-browser path the community module uses.
    // To keep that surface auditable, only two files may import the
    // contract: the OpenExternalUrlAction wrapper (validates https +
    // allow-listed host before invoking the contract) and the
    // NoOpShell fallback implementation (in-module impl bound when
    // NativePHP's NativeServiceProvider is absent).
    //
    // Per the W-7 pre-survey, no other module currently imports the
    // contract; the allow-list below is exact and adding a new file
    // requires a code-review justification mirroring the auth-facade
    // allow-list at the top of this file. Tests/ files are skipped
    // so feature suites can bind a ShellFake or substitute a custom
    // double without tripping the rule. The grep strips block + line
    // comments first so PHPDoc references stay legal.
    // Allow-list also covers the service provider that wires the
    // optional fallback binding: it imports the contract FQCN to
    // call `$this->app->bound(ShellContract::class)` before binding
    // the in-module NoOpShell. The provider performs no runtime
    // dispatch through the contract — it only inspects whether
    // another service provider has already registered an impl.
    $allowList = [
        'Modules/Community/Public/Actions/OpenExternalUrlAction.php',
        'Modules/Community/Internal/Shell/NoOpShell.php',
        'Modules/Community/Providers/CommunityServiceProvider.php',
    ];

    $modulesDir = base_path('Modules');
    if (! is_dir($modulesDir)) {
        expect(true)->toBeTrue();

        return;
    }

    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $relative = str_replace(base_path().'/', '', $path);
        if (in_array($relative, $allowList, true)) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/Native\\\\Desktop\\\\Contracts\\\\Shell\b/', $stripped) === 1) {
            $hits[] = $relative;
        }
    }

    expect($hits)->toBe(
        [],
        "Native\\Desktop\\Contracts\\Shell may only be imported by OpenExternalUrlAction + NoOpShell. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('every raw merchant_aliases query in production code carries an explicit user_id filter (noMerchantAliasesQueryWithoutUserIdFilter)', function (): void {
    // Cross-user posture for merchant_aliases: every production reader
    // and writer that goes through the raw query builder MUST carry an
    // explicit `where('user_id', ...)` filter (or include a `user_id`
    // column on the row payload for inserts). The BelongsToUser global
    // scope on the Eloquent model is a defence-in-depth secondary
    // guard; it does not fire under queue workers or console commands.
    //
    // The scan walks every `.php` file under Modules/Import that is
    // not a test file. For each occurrence of `->table('merchant_aliases')`
    // it looks ahead 30 lines for an explicit `user_id` reference
    // (either `where('user_id'` for reads/updates or `'user_id' =>` for
    // inserts). A site that fails this check is flagged so a future
    // contributor cannot silently introduce a cross-user leak.
    $importRoot = base_path('Modules/Import');
    if (! is_dir($importRoot)) {
        expect(true)->toBeTrue();

        return;
    }

    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($importRoot, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        $lines = explode("\n", $stripped);

        foreach ($lines as $index => $line) {
            if (! str_contains($line, "table('merchant_aliases')")) {
                continue;
            }
            // Look ahead 30 lines for a user_id reference. The window
            // is generous enough to cover a multi-line update payload
            // but tight enough that an unrelated downstream
            // `where('user_id')` on a different table does not falsely
            // satisfy the check.
            $window = implode("\n", array_slice($lines, $index, 30));
            $hasUserIdFilter = preg_match("/where\\(\\s*['\"]user_id['\"]/", $window) === 1
                || preg_match("/['\"]user_id['\"]\\s*=>/", $window) === 1;
            if (! $hasUserIdFilter) {
                $hits[] = sprintf('%s:%d', str_replace(base_path().'/', '', $path), $index + 1);
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Every raw `->table('merchant_aliases')` call in production code must carry an explicit `user_id` filter or column. Offenders:\n  ".implode("\n  ", $hits),
    );
});

arch('Modules\\Reports\\Internal is only used inside Modules\\Reports')
    ->expect('Modules\\Reports\\Internal')
    ->toOnlyBeUsedIn('Modules\\Reports');

it('does not allow Recurring/Budgets/DriftAlerts/Position/Ledger to import Modules\\Notifications (noTriggerModuleImportsNotifications)', function (): void {
    // D-38 invariant 1 (T-18-71) — plan 18-17. This is CONTEXT.md's own
    // pick for "the decision most likely to erode": nothing else in the
    // codebase catches a trigger module reaching straight for the
    // notification store, and the first "just one field" makes a direct
    // call the path of least resistance.
    //
    // D-28 requires every trigger module (Recurring, Budgets, DriftAlerts,
    // Position, Ledger) to stay wholly ignorant of `Modules\Notifications`:
    // it emits a readonly Public event (`PaymentReminderDue`,
    // `TransactionBatchImported`, etc.) and lets one of
    // `Modules\Notifications`'s `Persist*` listeners subscribe to it.
    //
    // The one legitimate exception is `routes/console.php`: application-
    // level wiring, not module code, which reads
    // `NotificationPreferenceQuery` and passes the preference-derived
    // `$leadDays` / `$cadence` into `EmitPaymentRemindersJob` /
    // `EmitPositionDigestJob` as constructor parameters — this walk never
    // visits `routes/`, so that bridge needs no allow-list entry.
    //
    // Comments are stripped BEFORE matching: `TransactionBatchImported`'s
    // own docblock names
    // `Modules\Notifications\Internal\Listeners\PersistCoalescedImport` to
    // explain who consumes the event, and an uncommented scan would make
    // the codebase's own explanatory prose trip the rule it is describing.
    $hits = [];
    $triggerDirs = [
        'Modules/Recurring',
        'Modules/Budgets',
        'Modules/DriftAlerts',
        'Modules/Position',
        'Modules/Ledger',
    ];

    foreach ($triggerDirs as $dir) {
        $absolute = base_path($dir);
        if (! is_dir($absolute)) {
            // Module folder lands in a later wave; until it does the rule
            // is trivially satisfied.
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/tests/')) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            if (preg_match('/Modules\\\\Notifications\\\\/', $stripped) === 1) {
                $hits[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($hits)->toBe(
        [],
        'Trigger modules (Recurring/Budgets/DriftAlerts/Position/Ledger) must never import '
        ."Modules\\Notifications — this inverts D-28's dependency direction, and it is the decision "
        .'most likely to erode: the first "just one field" makes a direct call the path of least '
        .'resistance. Emit a readonly Public event from the trigger module instead and let '
        .'Modules\\Notifications listen for it (routes/console.php is application wiring, not module '
        .'code, and is the one sanctioned bridge — see how EmitPaymentRemindersJob / '
        ."EmitPositionDigestJob take their preference-derived values as constructor parameters). Offenders:\n  "
        .implode("\n  ", $hits),
    );
});

it('does not allow the Desktop NativePHP facade allow-list to grow beyond the pinned set (pinnedDesktopFacadeAllowList)', function (): void {
    // D-38 invariant 2 (T-18-72) / D-31 — plan 18-17. Turns "the allow-list
    // and phpstan ignore list do not grow by even one entry" from a promise
    // into something CI proves. A NEW Desktop file touching a NativePHP
    // facade is exactly the "just add a helper" move this guards against —
    // route new native-chrome logic through the existing
    // `DispatchOsNotification` with plain constructor-DI collaborators
    // instead.
    //
    // Two DIFFERENT lists are pinned here, and they are deliberately NOT
    // the same length — each is read and pinned to its own real content:
    //   1. `phpstan.neon`'s `Native\Desktop\Facades\(Menu|Window|System|
    //      Notification|App|ChildProcess) facade should not be used.`
    //      ignoreErrors entry's `paths` list — 9 files.
    //   2. This file's own "no Laravel facade usage in module code" test's
    //      `->ignoring([...])` list, Desktop-only entries (its
    //      `Modules\Core\Public\Support\LockStore` entry belongs to a
    //      DIFFERENT carve-out — the Cache facade, not Native\Desktop — and
    //      is excluded from this pin) — 7 class names. This ignoring() list
    //      matches `Illuminate\Support\Facades\*` usage, a different rule
    //      from the `Native\Desktop\Facades\*` phpstan entry (NativePHP's
    //      facades are NOT `Illuminate\Support\Facades\*` classes), so the
    //      two lists cover different symbols and legitimately differ in
    //      length and membership.
    //
    // Scoped to the Desktop list ONLY — plan 18-15 legitimately grew the
    // separate `Native\Mobile\Facades\*` path list (a genuinely new,
    // sanctioned crossing point for the mobile local-notifications
    // plugin), so pinning that list here would fail on correct, reviewed
    // work.
    $pinnedPhpstanDesktopPaths = [
        'Modules/Desktop/Internal/NativeAppServiceProvider.php',
        'Modules/Desktop/Internal/Native/AppMenuBuilder.php',
        'Modules/Desktop/Internal/Native/OsThemeProbe.php',
        'Modules/Desktop/Internal/Native/NativeBiometricUnlock.php',
        'Modules/Desktop/Internal/Native/DesktopKeyCustodian.php',
        'Modules/Desktop/Internal/Listeners/DispatchOsNotification.php',
        'Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php',
        'Modules/Desktop/Internal/Listeners/NavigateOnNotificationDeepLink.php',
        'Modules/Desktop/Internal/Listeners/ApplyCloseWindowChoice.php',
    ];

    $pinnedIgnoringDesktopEntries = [
        'Modules\\Desktop\\Internal\\NativeAppServiceProvider',
        'Modules\\Desktop\\Internal\\Native\\AppMenuBuilder',
        'Modules\\Desktop\\Internal\\Native\\OsThemeProbe',
        'Modules\\Desktop\\Internal\\Listeners\\DispatchOsNotification',
        'Modules\\Desktop\\Internal\\Listeners\\SurfaceWorkerCrashAlert',
        'Modules\\Desktop\\Internal\\Listeners\\NavigateOnNotificationDeepLink',
        'Modules\\Desktop\\Internal\\Listeners\\ApplyCloseWindowChoice',
    ];

    // --- 1. phpstan.neon's Native\Desktop\Facades\* ignoreErrors entry ---
    $neon = Yaml::parseFile(base_path('phpstan.neon'));
    /** @var list<array<string, mixed>> $ignoreErrors */
    $ignoreErrors = is_array($neon['parameters']['ignoreErrors'] ?? null)
        ? $neon['parameters']['ignoreErrors']
        : [];

    $desktopEntry = null;
    foreach ($ignoreErrors as $entry) {
        $message = is_array($entry) && isset($entry['message']) && is_string($entry['message'])
            ? $entry['message']
            : null;
        if (
            $message !== null
            && str_contains($message, 'Native')
            && str_contains($message, 'Desktop')
            && str_contains($message, 'Facades')
            && str_contains($message, 'facade should not be used')
        ) {
            $desktopEntry = $entry;
            break;
        }
    }

    expect($desktopEntry)->not->toBeNull(
        'phpstan.neon must carry a Native\\Desktop\\Facades\\* "facade should not be used" ignoreErrors '
        .'entry — the Desktop native-chrome carve-out has disappeared entirely.',
    );

    /** @var array{paths?: mixed} $desktopEntry */
    $actualPaths = is_array($desktopEntry['paths'] ?? null) ? $desktopEntry['paths'] : [];

    expect($actualPaths)->toEqualCanonicalizing(
        $pinnedPhpstanDesktopPaths,
        "phpstan.neon's Native\\Desktop\\Facades\\* ignoreErrors path list has changed from the pinned "
        .'set. A NEW Desktop file touching a NativePHP facade is exactly what D-31 promises never to '
        .'permit silently — route new native-chrome logic through the existing DispatchOsNotification '
        ."with plain constructor-DI collaborators instead. Actual:\n  ".implode("\n  ", $actualPaths),
    );

    // --- 2. This file's own "no Laravel facade usage in module code" ->ignoring([...]) list ---
    $selfContents = (string) file_get_contents(__FILE__);
    if (preg_match(
        "/no Laravel facade usage in module code'\\)[\\s\\S]*?->ignoring\\(\\[([\\s\\S]*?)\\]\\)/",
        $selfContents,
        $m,
    ) !== 1) {
        throw new RuntimeException(
            'Could not locate the "no Laravel facade usage in module code" ->ignoring([...]) list in '
            .'this file — has the test been renamed or restructured?',
        );
    }

    $strippedList = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $m[1]) ?? $m[1];
    preg_match_all("/'([^']*)'/", $strippedList, $entryMatches);
    $ignoringDesktopEntries = array_values(array_filter(
        array_map(
            static fn (string $raw): string => str_replace('\\\\', '\\', $raw),
            $entryMatches[1],
        ),
        static fn (string $s): bool => str_starts_with($s, 'Modules\\Desktop\\'),
    ));

    expect($ignoringDesktopEntries)->toEqualCanonicalizing(
        $pinnedIgnoringDesktopEntries,
        "This file's own facade-usage ->ignoring([...]) list's Desktop-only entries have changed from "
        ."the pinned set. Actual:\n  ".implode("\n  ", $ignoringDesktopEntries),
    );
});
