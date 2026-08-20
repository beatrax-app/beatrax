<?php

declare(strict_types=1);
use Illuminate\Routing\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * @link ../../.docs/conventions/arch-invariants.md
 * @link ../../.docs/architecture/module-boundaries.md
 */

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

// Mobile is Sync's co-designed peer: Modules\Mobile\Internal\Sync\* is the
// second half of the device-to-device protocol and imports Sync\Internal
// transport and identity primitives (DeviceIdentityLoader, TransportFramer,
// PeerCatchUpExchanger) directly rather than through Sync\Public.
arch('Modules\\Sync\\Internal is only used inside Modules\\Sync (Mobile peer allow-listed)')
    ->expect('Modules\\Sync\\Internal')
    ->toOnlyBeUsedIn(['Modules\\Sync', 'Modules\\Mobile']);

// There is deliberately no Public unlock seam — AppLockKeyService exposes only
// release()/withhold() — so Sync's two test-infrastructure base classes call
// LockStateManager->unlock() directly to exercise real crypto. Scoped to those
// two classes so production Sync code reaching into Auth\Internal still fails.
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

arch('Modules\\Budgets\\Internal is only used inside Modules\\Budgets')
    ->expect('Modules\\Budgets\\Internal')
    ->toOnlyBeUsedIn('Modules\\Budgets');

arch('Modules\\Calendar\\Internal is only used inside Modules\\Calendar')
    ->expect('Modules\\Calendar\\Internal')
    ->toOnlyBeUsedIn('Modules\\Calendar');

arch('Modules\\CashBook\\Internal is only used inside Modules\\CashBook')
    ->expect('Modules\\CashBook\\Internal')
    ->toOnlyBeUsedIn('Modules\\CashBook');

arch('Modules\\FX\\Internal is only used inside Modules\\FX')
    ->expect('Modules\\FX\\Internal')
    ->toOnlyBeUsedIn('Modules\\FX');

arch('Modules\\Goals\\Internal is only used inside Modules\\Goals')
    ->expect('Modules\\Goals\\Internal')
    ->toOnlyBeUsedIn('Modules\\Goals');

arch('Modules\\Migration\\Internal is only used inside Modules\\Migration')
    ->expect('Modules\\Migration\\Internal')
    ->toOnlyBeUsedIn('Modules\\Migration');

arch('Modules\\Notifications\\Internal is only used inside Modules\\Notifications')
    ->expect('Modules\\Notifications\\Internal')
    ->toOnlyBeUsedIn('Modules\\Notifications');

arch('Modules\\OpenBanking\\Internal is only used inside Modules\\OpenBanking')
    ->expect('Modules\\OpenBanking\\Internal')
    ->toOnlyBeUsedIn('Modules\\OpenBanking');

arch('Modules\\Position\\Internal is only used inside Modules\\Position')
    ->expect('Modules\\Position\\Internal')
    ->toOnlyBeUsedIn('Modules\\Position');

arch('Modules\\Pots\\Internal is only used inside Modules\\Pots')
    ->expect('Modules\\Pots\\Internal')
    ->toOnlyBeUsedIn('Modules\\Pots');

arch('Modules\\Tax\\Internal is only used inside Modules\\Tax')
    ->expect('Modules\\Tax\\Internal')
    ->toOnlyBeUsedIn('Modules\\Tax');

// Module Routes/web.php files are closures under Modules\<Name>\Routes, not
// classes, so pest-arch's file walk never classifies them and the Route facade
// they use is invisible to this rule. A future class-based router shape would
// be caught, and would need an explicit entry below.
arch('no Laravel facade usage in module code')
    ->expect('Illuminate\\Support\\Facades')
    ->not->toBeUsedIn('Modules')
    ->ignoring([
        // Laravel calls ShouldBeUniqueUntilProcessing::uniqueVia() at queue-push
        // time, before the job's constructor DI completes, so an injected Cache
        // repository is unreachable from a uniqueVia() body. Every one of them
        // returns LockStore::forUniqueJobs(), confining the crossing to this file.
        'Modules\\Core\\Public\\Support\\LockStore',
        // NativePHP's window, app-menu, OS-theme and notification APIs are only
        // reachable through its facades, which NativePHP invokes outside the
        // container lifecycle — there is no constructor-injection seam. Every
        // other collaborator in these classes still arrives through DI.
        'Modules\\Desktop\\Internal\\NativeAppServiceProvider',
        'Modules\\Desktop\\Internal\\Native\\AppMenuBuilder',
        'Modules\\Desktop\\Internal\\Native\\OsThemeProbe',
        'Modules\\Desktop\\Internal\\Listeners\\DispatchOsNotification',
        'Modules\\Desktop\\Internal\\Listeners\\SurfaceWorkerCrashAlert',
        'Modules\\Desktop\\Internal\\Listeners\\NavigateOnNotificationDeepLink',
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
    $hits = [];
    $resolversDir = base_path('Modules/Chains/Internal/Resolvers');
    if (! is_dir($resolversDir)) {
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
    // The PayPal Reporting API integration is deferred behind a business-account
    // upgrade; nothing may land a paypal-api adapter or route ahead of it.
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
    // EmailScan owns the fetch + persist-.eml + index pipeline only. Transitions
    // out of inbox_messages.status='fetched' and every write against the
    // transactions table belong to the Receipts matcher.
    $hits = [];
    $emailScanDir = base_path('Modules/EmailScan');
    if (! is_dir($emailScanDir)) {
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
    // The grep targets the ->update() shape on the table specifically, so
    // insertOrIgnore and the migrations' own seed inserts stay legal.
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
    // backfill_progress sits on the inboxes table rather than inbox_scan_state,
    // but it is a per-inbox lifecycle signal, so routing it through
    // InboxScanStateMachine keeps the sole-mutator invariant whole. The grep
    // targets UPDATE only: OAuthCallbackController's first-connect INSERT is legal.
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
    // Receipts owns the matcher pipeline and the .eml/.mbox drop-in, and reads
    // EmailScan only through InboxMessageQuery plus the on-disk .eml — never a
    // provider client or an OAuth surface. This is the EmailScan rule flipped.
    $hits = [];
    $receiptsDir = base_path('Modules/Receipts');
    if (! is_dir($receiptsDir)) {
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
    // OAuth client secrets and refresh tokens live exclusively in the chmod-600
    // JSON repository on disk; no EmailScan migration may add a column for one.
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
    // Recurring is analytical-only: transaction-type ownership stays with the
    // Ledger income detector.
    $hits = [];
    $recurringDir = base_path('Modules/Recurring');
    if (! is_dir($recurringDir)) {
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
    // Non-state columns (latest amount, monthly equivalent, next-expected-charge,
    // funding-chain link) may be refreshed without the state machine, so the grep
    // targets the `state` key inside the update payload specifically.
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
    // DriftAlerts is analytical-only. The grep targets write verbs alone —
    // cross-module reads go through Recurring's Public surface, which the
    // crossModuleAccessGoesThroughPublic rule above covers.
    $hits = [];
    $driftDir = base_path('Modules/DriftAlerts');
    if (! is_dir($driftDir)) {
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
    // snoozed_until and actioned_at may be updated without the state machine, so
    // the grep targets the `state` key inside the update payload specifically.
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
    // snoozed_until, actioned_at and dismissed_as may be updated without the
    // state machine, so the grep targets the `state` key in the payload only.
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
    $hits = [];
    $forecastingDir = base_path('Modules/Forecasting');
    if (! is_dir($forecastingDir)) {
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
    // forecast_scenario_mutations rows are hypothetical what-if changes a user
    // saved into a scenario; JOINing them onto the transaction substrate would
    // let a scenario silently bleed into a real-money read. The scan walks the
    // whole Modules/ tree because any module reaching for that JOIN causes it.
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
    // system_alerts is a purely operational surface. JOINing it onto transactions
    // would let a background-job failure bleed into a real-money read, blurring
    // the operational/domain separation the rest of the app relies on.
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
    // Facade-rooted calls cannot be substituted from a test harness, which kills
    // the artisan-test story for db:backup, db:restore, beatrax:doctor and
    // beatrax:failed-jobs. Core's console commands take everything through DI.
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
    // Companion to the facade grep above: the *_path() and container-helper
    // family resolves through the singleton Application container but imports no
    // Facades namespace, so that scan misses it. Scope matches it too — other
    // modules still carry pre-existing helper usage.
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
    // Current-user identity travels through the constructor-injected CurrentUser
    // contract. The Auth facade, the auth()/session() helpers and
    // request()->user() all resolve through the singleton container and cannot be
    // substituted from a test harness. The allow-list below is per-file, never a glob.
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
    // Every filesystem path flows through Modules\Core\Public\Services\
    // UserDataPathService so a NativePHP build can retarget the storage root; it
    // is the sole sanctioned caller of base_path(). Blade files are exempt from
    // the literal check — user-facing <code> tags legitimately display paths.
    $allowList = [
        'Modules/Core/Public/Services/UserDataPathService.php',
    ];

    $bannedHelpers = '/(?<![>:])\b(database_path|storage_path|base_path)\s*\(/';
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
    // laravel/horizon is a require-dev package and the /horizon dashboard
    // serialises transaction data, so no shipped-build file may name a
    // Laravel\Horizon\ symbol. The class_exists() guard in bootstrap/providers.php
    // is stripped before scanning: it is what keeps a --no-dev tree from fataling.
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
    // NativePHP's API lives under Native\Desktop\ and the desktop shell is
    // quarantined inside Modules/Desktop. Community's carve-out is the
    // system-browser hop: OpenExternalUrlAction validates https and an
    // allow-listed host before delegating to the Shell contract.
    $allowList = [
        'Modules/Community/Public/Actions/OpenExternalUrlAction.php',
        'Modules/Community/Internal/Shell/NoOpShell.php',
        'Modules/Community/Providers/CommunityServiceProvider.php',
        // A NativePHP-published stub, re-generated by the composer
        // post-update-cmd hook and never registered in bootstrap/providers.php,
        // so it cannot be durably deleted. The real provider is
        // Modules/Desktop/Internal/NativeAppServiceProvider.
        'app/Providers/NativeAppServiceProvider.php',
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
    // The dark theme uses the Tailwind class strategy, so an element carrying a
    // hard-coded light surface utility must also carry the matching dark:
    // companion. The check runs per class-attribute string — the set one element
    // carries — because a dark: utility elsewhere in the file proves nothing.
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
            $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents;
            $relative = str_replace(base_path().'/', '', $path);

            foreach ($classStringsOf($stripped) as $classString) {
                $hasBgWhite = preg_match('/(?<![:\w-])bg-white(?![\w])/', $classString) === 1;
                if ($hasBgWhite && preg_match('/dark:bg-/', $classString) !== 1) {
                    $hits[] = $relative.' — bg-white without dark:bg- companion';
                }
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
    // The dropped "Act as partner" feature's guard-swap and banner-paint files
    // must stay absent. The check is file_exists, not class_exists:
    // class_exists() runs the Composer autoloader, which can still hold a stale
    // entry for a just-deleted file and emit a misleading open-stream warning.
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
    // The diederik -> Beatrax rename must stay complete. The three allow-listed
    // files deliberately house the literal as their assertion subject: this
    // invariant, the artisan-signature guard, and the sidebar render test.
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
            // Pest snapshot baselines are re-baselined alongside the source
            // rename, so scanning them would double-count that work.
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
    // Every /dev/* route must apply ensureDeveloperMode so the 404-not-403
    // information-disclosure mitigation covers the whole surface. The check walks
    // the runtime route table rather than the source files, because the alias
    // only resolves through gatherMiddleware(), which expands group middleware.
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
    // Only `online` and `direct_debit` are scanned. The other PaymentType values
    // collide with unrelated domains — transactions.type, categorisation slugs
    // and PayPal CSV event types take transfer/cash/fee/refund/unknown, and `pin`
    // is also the Auth/Mobile app-lock term. Callers use PaymentType::* instead.
    $needles = ['online', 'direct_debit'];
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
        // The BIP39 mnemonic word list carries the English word "online" as
        // fixed list data, not as a PaymentType value.
        if (str_ends_with($path, 'Modules/Sync/Internal/Pairing/Bip39WordList.php')) {
            continue;
        }
        // The chip-label translations are keyed by the enum's own values —
        // chipLabel() builds the key from $this->value, so no caller types one.
        if (str_ends_with($path, '/payment_type.php') && str_contains($path, '/Resources/lang/')) {
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
    // A class named *Hinter that omits the implements clause silently fails the
    // iterable<PaymentTypeHinter> type at the classifier seam. Reflection
    // resolves the implements list rather than a grep, so a subclass that
    // inherits the contract still counts.
    $parsersDir = base_path('Modules/Import/Internal/Parsers');
    if (! is_dir($parsersDir)) {
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
    // The Shell contract is the only outbound system-browser path. Two files may
    // import it — OpenExternalUrlAction, which validates https and an
    // allow-listed host first, and the NoOpShell fallback — plus the provider,
    // which names the FQCN only to check app->bound() before binding that fallback.
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
    // Every raw merchant_aliases read and write must carry an explicit user_id
    // filter: the BelongsToUser global scope on the Eloquent model is secondary
    // defence only and does not fire under queue workers or console commands,
    // which is exactly where a missing filter becomes a cross-user leak.
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
            // The 30-line window covers a multi-line update payload without
            // letting an unrelated later where('user_id') on another table pass.
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
    // Trigger modules stay wholly ignorant of Modules\Notifications: each emits a
    // readonly Public event and one of Notifications' Persist* listeners
    // subscribes. Comments are stripped before matching because
    // TransactionBatchImported's own docblock names the listener consuming it.
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
    // Two DIFFERENT lists are pinned here, and they legitimately differ in length
    // and membership: phpstan.neon's ignoreErrors entry covers
    // Native\Desktop\Facades\*, while this file's own ->ignoring([...]) covers
    // Illuminate\Support\Facades\* — NativePHP's facades are not that namespace.
    $pinnedPhpstanDesktopPaths = [
        'Modules/Desktop/Internal/NativeAppServiceProvider.php',
        'Modules/Desktop/Internal/Native/AppMenuBuilder.php',
        'Modules/Desktop/Internal/Native/OsThemeProbe.php',
        'Modules/Desktop/Internal/Native/NativeBiometricUnlock.php',
        'Modules/Desktop/Internal/Native/DesktopKeyCustodian.php',
        // Not new facade surface: the ChildProcess calls that start sync:serve
        // and the pairing relay moved here out of NativeAppServiceProvider so the
        // boot hook and the DeviceSyncEnabled listener share one owner.
        'Modules/Desktop/Internal/Native/SyncListenerProcess.php',
        'Modules/Desktop/Internal/Native/RelayListenerProcess.php',
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
    // Scoped to Desktop: the sibling Native\Mobile\Facades\* path list
    // legitimately grew for the mobile local-notifications plugin.
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

it('derives the deterministic notification key in exactly one place and mutates notifications.state through exactly one class, naming OpLogReplayer as the legitimate read_at/dismissed_at mutator (onlyOneNotificationKeyDeriverAndStateMutator)', function (): void {
    // Two devices must derive the SAME sha256 id from the same
    // (user_id, trigger_type, subject_key, occurrence) tuple or they never
    // converge on one row. A second deriver surfaces months later as duplicate
    // rows on merge, never as a failing test — hence the two narrow checks below.
    $notificationsDir = base_path('Modules/Notifications');
    $keyDeriverFile = base_path('Modules/Notifications/Internal/Support/DeterministicKeyDeriver.php');
    $stateMachineFile = base_path('Modules/Notifications/Internal/StateMachines/NotificationStateMachine.php');
    $allowedDirectCallers = [
        base_path('Modules/Notifications/Internal/Support/NotificationWriter.php'),
        base_path('Modules/Notifications/Internal/Listeners/ResolveSettledReminder.php'),
        // Re-derives an EXISTING row's id to mark it read/dismissed — the same
        // "look up, do not mint" shape as ResolveSettledReminder.
        base_path('Modules/Notifications/Database/Seeders/Demo/DemoNotificationsSeeder.php'),
    ];

    $sha256Hits = [];
    $illegalCallerHits = [];
    $stateMutationHits = [];

    if (is_dir($notificationsDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($notificationsDir, RecursiveDirectoryIterator::SKIP_DOTS),
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

            // A. sha256 confined to DeterministicKeyDeriver.
            if ($path !== $keyDeriverFile && preg_match("/hash\\s*\\(\\s*['\"]sha256['\"]/", $stripped) === 1) {
                $sha256Hits[] = str_replace(base_path().'/', '', $path).' (sha256 hashing outside DeterministicKeyDeriver)';
            }

            // Direct ->derive( callers restricted to the allow-list.
            if ($path !== $keyDeriverFile && ! in_array($path, $allowedDirectCallers, true)) {
                if (preg_match('/->derive\s*\(/', $stripped) === 1 || preg_match('/::derive\s*\(/', $stripped) === 1) {
                    $illegalCallerHits[] = str_replace(base_path().'/', '', $path).' (calls DeterministicKeyDeriver::derive() directly)';
                }
            }

            // notifications.state, mutated only by NotificationStateMachine.
            if ($path !== $stateMachineFile && ! str_starts_with($path, base_path('Modules/Notifications/Database/Migrations').DIRECTORY_SEPARATOR)) {
                if (
                    preg_match("/->table\\(['\"]notifications['\"]\\)[^;]*->update\\s*\\(\\s*\\[\\s*['\"]state['\"]/", $stripped) === 1
                    || preg_match('/Notification::query\(\)[^;]*->update\(\s*\[\s*[\'"]state[\'"]/', $stripped) === 1
                ) {
                    $stateMutationHits[] = str_replace(base_path().'/', '', $path);
                }
            }
        }
    }

    // B. Repo-wide check for a second, independently-built notification key. Not
    // a blanket sha256 ban: Sync's pairing, Ledger's FingerprintComposer and
    // Receipts' .eml hashing all hash legitimately. The signature of a rebuilt
    // key is a sha256 call alongside all three tuple field names.
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
        if ($path === $keyDeriverFile || str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match("/hash\\s*\\(\\s*['\"]sha256['\"]/", $stripped) === 1
            && preg_match('/trigger_type/', $stripped) === 1
            && preg_match('/subject_key/', $stripped) === 1
            && preg_match('/occurrence/', $stripped) === 1
        ) {
            $sha256Hits[] = str_replace(base_path().'/', '', $path).' (possible second notification-key derivation — combines a sha256 hash with the trigger_type/subject_key/occurrence tuple)';
        }
    }

    expect(array_unique($sha256Hits))->toBe(
        [],
        'Reqs 1/12 depend on two devices computing an IDENTICAL notification key from identical '
        .'inputs; a second, slightly-different implementation breaks convergence SILENTLY — it '
        .'surfaces only as mysterious duplicate rows on merge, never as a failing test. The '
        ."deterministic key must be derived ONLY in DeterministicKeyDeriver::derive(). Offenders:\n  "
        .implode("\n  ", array_unique($sha256Hits)),
    );

    expect($illegalCallerHits)->toBe(
        [],
        'DeterministicKeyDeriver::derive() may only be called directly by NotificationWriter (every '
        .'fresh write) and the two legitimate re-derive sites (ResolveSettledReminder, '
        ."DemoNotificationsSeeder). Offenders:\n  ".implode("\n  ", $illegalCallerHits),
    );

    expect($stateMutationHits)->toBe(
        [],
        'Only NotificationStateMachine may mutate notifications.state. OpLogReplayer IS a legitimate '
        .'second mutator of notifications.read_at / notifications.dismissed_at (LWW-per-field replay of '
        .'a peer'."'".'s ops) — but it is NOT, and must never become, a mutator of notifications.state, '
        .'which is a locally-derived column deliberately excluded from '
        ."MergeRulesRegistry['notifications'] (18-01 / 18-04). Offenders:\n  ".implode("\n  ", $stateMutationHits),
    );
});

it('evaluates notification-delivery suppression (quiet hours + per-trigger toggles) in exactly one place (onlyOneSuppressionEvaluator)', function (): void {
    // Two independently-written suppression checks WILL drift, and "my phone
    // ignores quiet hours" has no failing test to point at. Both delivery
    // adapters must call SuppressionEvaluator::shouldDeliver() and re-implement
    // no slice of the quiet-hours / per-trigger-toggle logic themselves.
    $bannedLiterals = [
        'quiet_hours',
        'quietHours',
        'reminders_enabled',
        'budget_nudges_enabled',
        'savings_prompts_enabled',
        'digest_cadence',
        'notification_preferences',
    ];
    $adapterFiles = [
        base_path('Modules/Desktop/Internal/Listeners/DispatchOsNotification.php'),
        base_path('Modules/Mobile/Internal/Listeners/DispatchMobileNotification.php'),
    ];

    $literalHits = [];
    foreach ($adapterFiles as $path) {
        if (! is_file($path)) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        foreach ($bannedLiterals as $literal) {
            if (preg_match('/'.preg_quote($literal, '/').'/', $stripped) === 1) {
                $literalHits[] = str_replace(base_path().'/', '', $path)." references '{$literal}' directly instead of going through SuppressionEvaluator";
            }
        }
    }

    expect($literalHits)->toBe(
        [],
        'Neither delivery adapter (Desktop nor Mobile) may reference a quiet-hours / per-trigger-toggle '
        .'preference literal directly — both must consult SuppressionEvaluator::shouldDeliver() and '
        ."nothing else. Two independently-written suppression checks WILL drift, and D-31/D-32's two "
        ."adapters are exactly where that drift would land. Offenders:\n  ".implode("\n  ", $literalHits),
    );

    // Second half: notification_preferences is read only through its Public seam.
    $allowedReaders = [
        base_path('Modules/Notifications/Public/Services/NotificationPreferenceQuery.php'),
    ];
    $modulesDir = base_path('Modules');
    $readerHits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
            continue;
        }
        if (in_array($path, $allowedReaders, true)) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (
            preg_match("/->table\\(\\s*['\"]notification_preferences['\"]\\s*\\)/", $stripped) === 1
            || preg_match('/NotificationPreference::query\s*\(/', $stripped) === 1
            || preg_match('/NotificationPreference::where\s*\(/', $stripped) === 1
        ) {
            $readerHits[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($readerHits)->toBe(
        [],
        'Only NotificationPreferenceQuery may read the notification_preferences table directly outside '
        ."SuppressionEvaluator's own preference lookup (which itself goes through "
        ."NotificationPreferenceQuery::forCurrentDevice()). Offenders:\n  ".implode("\n  ", $readerHits),
    );
});

it('requires every module with an Internal namespace to carry a boundary arch rule (everyInternalNamespaceHasABoundaryRule)', function (): void {
    // The per-module boundary rules above are hand-maintained, and the list
    // silently drifted once: eleven modules shipped an Internal/ namespace with
    // no rule at all. The needle is the exact top-level `'Modules\<X>\Internal'`
    // target, so a rule naming a deeper symbol does not falsely satisfy it.
    $selfContents = (string) file_get_contents(__FILE__);

    $modulesDir = base_path('Modules');
    $missing = [];

    /** @var SplFileInfo $entry */
    foreach (new DirectoryIterator($modulesDir) as $entry) {
        if (! $entry->isDir() || $entry->isDot()) {
            continue;
        }
        $module = $entry->getFilename();
        $internalDir = $modulesDir.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'Internal';
        if (! is_dir($internalDir)) {
            continue;
        }

        // An empty Internal/ skeleton is trivially safe; only populated ones count.
        $hasInternalClass = false;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($internalDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.php$/', $file->getPathname()) === 1) {
                $hasInternalClass = true;
                break;
            }
        }
        if (! $hasInternalClass) {
            continue;
        }

        $needle = "'Modules\\\\{$module}\\\\Internal'";
        if (! str_contains($selfContents, $needle)) {
            $missing[] = $module;
        }
    }

    sort($missing);

    expect($missing)->toBe(
        [],
        'Every module with a populated Internal/ namespace must carry a top-level boundary arch rule in '
        ."this file (arch('Modules\\\\<X>\\\\Internal ...')->expect('Modules\\\\<X>\\\\Internal')). Missing "
        ."for:\n  ".implode("\n  ", $missing),
    );
});
