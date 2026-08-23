<?php

declare(strict_types=1);
use Illuminate\Routing\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * @link ../../.docs/conventions/arch-invariants.md
 * @link ../../.docs/architecture/module-boundaries.md
 * @link ../../.docs/architecture/table-ownership.md
 */

// There is deliberately no per-module `Modules\<X>\Internal is only used inside
// Modules\<X>` rule here any more: pest-arch could not see most of this tree.
// pinnedCrossModuleInternalImports at the bottom of this file replaced all 34.

// The exception is Internal\Http\Livewire, which pest-arch does resolve: a
// component another module mounts now lives in Public\Http\Livewire, so an
// import of a neighbour's Internal component is a real violation again.
// The alias channel stays pinned below — a mount is not an import.
foreach (glob(dirname(__DIR__, 2).'/Modules/*/Internal/Http/Livewire', GLOB_ONLYDIR) ?: [] as $livewireDirectory) {
    $livewireModule = basename(dirname($livewireDirectory, 3));

    arch('Modules\\'.$livewireModule.'\\Internal\\Http\\Livewire is only used inside Modules\\'.$livewireModule)
        ->expect('Modules\\'.$livewireModule.'\\Internal\\Http\\Livewire')
        ->toOnlyBeUsedIn('Modules\\'.$livewireModule);
}

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

arch('ProjectionPipeline is never imported by Modules\\Forecasting\\Internal\\Http (noSynchronousForecastingInRequestLifecycle)')
    ->expect('Modules\\Forecasting\\Internal\\Pipeline\\ProjectionPipeline')
    ->not->toBeUsedIn([
        'Modules\\Forecasting\\Internal\\Http',
        'Modules\\Forecasting\\Resources',
    ]);

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
        'Modules/Shell/tests/Feature/AppSidebarRenderTest.php',
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
        "Every diederik / Diederik literal must be flipped to Beatrax. Offenders:\n  ".implode("\n  ", $hits),
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
    // NativePHP's facades live under Native\Desktop\Facades\*, so the only
    // list that can constrain them is phpstan.neon's ignoreErrors entry; this
    // file's own facade rule matches Illuminate\Support\Facades\* and never
    // saw these classes at all.
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

    // phpstan.neon's Native\Desktop\Facades\* ignoreErrors entry.
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

/** @return string the source with every comment blanked out, line offsets intact */
function boundaryBlankComments(string $source): string
{
    return (string) preg_replace_callback(
        '#/\*.*?\*/|//[^\n]*#s',
        static function (array $match): string {
            $newlines = substr_count($match[0], "\n");

            return str_repeat(' ', strlen($match[0]) - $newlines).str_repeat("\n", $newlines);
        },
        $source,
    );
}

/**
 * @return array{owner: array<string, string>, creators: array<string, list<string>>, altered: array<string, list<string>>}
 */
function boundaryTableOwnership(): array
{
    // Three spellings create a table here: the Schema facade, ModuleMigration's
    // injected schema(), and raw CREATE TABLE (the FTS5 virtual tables and
    // hlc_clock_state, which a Blueprint cannot express).
    $createdBy = [
        '/(?:Schema::|schema\(\)->|\$schema->)create\(\s*[\'"]([a-z0-9_]+)[\'"]/',
        '/CREATE\s+(?:VIRTUAL\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-z0-9_]+)/i',
    ];
    $alteredBy = '/(?:Schema::|schema\(\)->|\$schema->)table\(\s*[\'"]([a-z0-9_]+)[\'"]/';

    $migrations = array_merge(
        glob(base_path('Modules/*/Database/Migrations/*.php')) ?: [],
        glob(base_path('database/migrations/*.php')) ?: [],
    );
    sort($migrations);

    $creators = [];
    $altered = [];
    foreach ($migrations as $path) {
        $relative = substr($path, strlen(base_path()) + 1);
        $module = preg_match('#^Modules/([^/]+)/#', $relative, $m) === 1 ? $m[1] : '@root';
        $source = boundaryBlankComments((string) file_get_contents($path));

        foreach ($createdBy as $pattern) {
            if (preg_match_all($pattern, $source, $found) === 0) {
                continue;
            }
            foreach ($found[1] as $table) {
                $table = strtolower($table);
                if (! in_array($module, $creators[$table] ?? [], true)) {
                    $creators[$table][] = $module;
                }
            }
        }

        if (preg_match_all($alteredBy, $source, $found) > 0) {
            foreach ($found[1] as $table) {
                if (! in_array($module, $altered[$table] ?? [], true)) {
                    $altered[$table][] = $module;
                }
            }
        }
    }

    ksort($creators);
    ksort($altered);

    $owner = [];
    foreach ($creators as $table => $modules) {
        $owner[$table] = $modules[0];
    }

    return ['owner' => $owner, 'creators' => $creators, 'altered' => $altered];
}

/**
 * @return list<string> the methods invoked at the top level of the fluent chain
 *                      starting at $offset, up to the end of the statement
 */
function boundaryChainMethods(string $source, int $offset): array
{
    $length = strlen($source);
    $depth = 0;
    $names = [];

    for ($i = $offset; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === "'" || $char === '"') {
            $quote = $char;
            for ($i++; $i < $length; $i++) {
                if ($source[$i] === '\\') {
                    $i++;

                    continue;
                }
                if ($source[$i] === $quote) {
                    break;
                }
            }

            continue;
        }
        if ($char === '(' || $char === '[' || $char === '{') {
            $depth++;

            continue;
        }
        if ($char === ')' || $char === ']' || $char === '}') {
            if (--$depth < 0) {
                break;
            }

            continue;
        }
        if ($depth !== 0) {
            continue;
        }
        if ($char === ';') {
            break;
        }
        // Depth zero is what separates the chain's own terminal call from an
        // ->update() belonging to a closure handed to ->chunkById().
        if ($char === '-' && preg_match('/^->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', substr($source, $i, 64), $m) === 1) {
            $names[] = $m[1];
        }
    }

    return $names;
}

/**
 * @param  array<string, string>  $owner
 * @return array<string, list<int>> "path table" => the lines that write it, ascending
 */
function boundaryCrossModuleTableWrites(array $owner): array
{
    $writeMethods = [
        'update', 'updateOrInsert', 'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing',
        'upsert', 'delete', 'forceDelete', 'truncate', 'increment', 'decrement',
        'incrementEach', 'decrementEach',
    ];
    $tableReference = '/(?:DB::table|->table|->from|->fromSub|->join|->joinSub|->joinLateral'
        .'|->leftJoin|->leftJoinSub|->rightJoin|->rightJoinSub|->crossJoin|->crossJoinSub)'
        .'\(\s*[\'"]([a-z0-9_]+)[\'"]/';
    // The builder is not the only raw seam. `$connection->update('UPDATE transactions …')`
    // names its table inside a string, which every table-name grep here used to miss.
    $rawStatement = '/\b(?:INSERT\s+(?:OR\s+[A-Z]+\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM'
        .'|TRUNCATE(?:\s+TABLE)?)\s+[`"]?([a-z0-9_]+)[`"]?/i';

    $modulesDir = base_path('Modules');
    if (! is_dir($modulesDir)) {
        return [];
    }

    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }
        $relative = substr($path, strlen(base_path()) + 1);
        if (preg_match('#^Modules/([^/]+)/#', $relative, $m) !== 1) {
            continue;
        }
        $module = $m[1];
        if (str_contains($relative, '/tests/') || str_contains($relative, '/Database/')) {
            continue;
        }

        $source = boundaryBlankComments((string) file_get_contents($path));

        if (preg_match_all($tableReference, $source, $found, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($found[0] as $index => [, $offset]) {
                $table = $found[1][$index][0];
                if (($owner[$table] ?? $module) === $module) {
                    continue;
                }
                if (array_intersect(boundaryChainMethods($source, $offset), $writeMethods) === []) {
                    continue;
                }
                $hits[] = [$relative, substr_count($source, "\n", 0, $offset) + 1, $table];
            }
        }

        if (preg_match_all($rawStatement, $source, $found, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($found[1] as $index => [$table]) {
                $table = strtolower($table);
                if (($owner[$table] ?? $module) === $module) {
                    continue;
                }
                $hits[] = [$relative, substr_count($source, "\n", 0, $found[0][$index][1]) + 1, $table];
            }
        }
    }

    // Grouped by file and table, not by line: the builder and the raw-SQL pass
    // can both land on one line, so the line is what de-duplicates them.
    $byKey = [];
    foreach ($hits as [$path, $line, $table]) {
        $key = "{$path} {$table}";
        if (! in_array($line, $byKey[$key] ?? [], true)) {
            $byKey[$key][] = $line;
        }
    }
    ksort($byKey);

    return array_map(static function (array $lines): array {
        sort($lines);

        return $lines;
    }, $byKey);
}

it('pins every cross-module raw-table write to the allow-list (crossModuleRawTableWrites)', function (): void {
    // Reads stay unrestricted on purpose: most cross-module raw-table references
    // are legitimate joins and narrowing them is a separate argument. A write is
    // where one module changes another module's state, which is the coupling.
    $pinned = [
        'Modules/Anomaly/Internal/Jobs/BackfillAnomaliesJob.php users 1',
        'Modules/Auth/Internal/Account/UserScopedDataPurge.php relay_mailbox 1',
        'Modules/Auth/Internal/Account/UserScopedDataPurge.php users 1',
        'Modules/Auth/Internal/Console/ResetPasswordCommand.php users 1',
        'Modules/Auth/Internal/Http/Livewire/ChangePasswordPage.php sessions 1',
        'Modules/Auth/Internal/Http/Livewire/ChangePasswordPage.php users 1',
        'Modules/Auth/Internal/Http/Livewire/ManageUserPage.php users 1',
        'Modules/Auth/Public/Actions/DeleteAccountAction.php transaction_search_fts 1',
        'Modules/Auth/Public/Actions/DeleteAccountAction.php users 1',
        'Modules/Auth/Public/Actions/ResetPasswordAction.php sessions 1',
        'Modules/Auth/Public/Actions/ResetPasswordAction.php users 1',
        'Modules/Auth/Public/Actions/SignupAction.php users 1',
        'Modules/Budgets/Public/Services/EnvelopeActivationService.php users 2',
        'Modules/CashBook/Internal/Http/Livewire/CashBookPage.php transactions 1',
        'Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php merchant_memories 2',
        'Modules/Chains/Internal/Resolvers/RetypeByAliasResolver.php transactions 1',
        'Modules/Core/Internal/Console/FailedJobsCommand.php failed_jobs 1',
        // The enable-time sweep and its rollback restore reach six tables this
        // module does not own, all through one table-agnostic batched writer in
        // PreMigrationSnapshot, so no table literal reaches this scan. The five
        // projection tables were already written that way; op_log_entries now is too.
        'Modules/Core/Public/Services/EncryptionMigrationService.php sync_encryption_state 4',
        'Modules/Counterparties/Internal/Jobs/CounterpartyGarbageCollectorJob.php transactions 1',
        'Modules/DevMode/Internal/Queue/QueueActions.php jobs 3',
        'Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php accounts 1',
        'Modules/Forecasting/Public/Actions/SetAccountOpeningBalance.php accounts 1',
        'Modules/Import/Public/Actions/ApplyEnrichments.php pending_enrichment_conflicts 1',
        'Modules/Import/Public/Actions/ApplyEnrichments.php transactions 1',
        // The counterparty blind-index sweep re-keys the matching columns of
        // three tables in one transaction, because they are compared against
        // each other; splitting it across module seams would let a device sit
        // with two of the three converted. See sensitive-columns-at-rest.md.
        'Modules/Ledger/Public/Services/CounterpartyKeyBackfill.php chain_links 1',
        'Modules/Ledger/Public/Services/CounterpartyKeyBackfill.php recurring_series 1',
        'Modules/Migration/Internal/Pipeline/EntityChangeApplier.php transactions 1',
        'Modules/Migration/Internal/Pipeline/PromoteStagingToDomain.php import_runs 1',
        'Modules/Migration/Internal/Pipeline/PromoteStagingToDomain.php transactions 1',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php device_registry 1',
        'Modules/Onboarding/Internal/Http/Livewire/Steps/FirstImportStep.php accounts 1',
        'Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php inbox_messages 2',
        'Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php pending_enrichment_conflicts 1',
        'Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php transactions 1',
        'Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php users 1',
        'Modules/Sync/Internal/Merge/TransferPairCascade.php transactions 1',
        'Modules/Transfers/Internal/Services/TransferPairer.php transactions 2',
    ];

    $ownership = boundaryTableOwnership();

    // Ownership only means something while exactly one module creates a table:
    // with two the map silently picks one, so fail on the ambiguity instead.
    $contested = array_keys(array_filter($ownership['creators'], static fn (array $m): bool => count($m) > 1));
    expect($contested)->toBe([], 'A table must be created by exactly one module. Contested:'
        ."\n  ".implode("\n  ", array_map(
            static fn (string $table): string => $table.': '.implode(', ', $ownership['creators'][$table]),
            $contested,
        )));

    $found = boundaryCrossModuleTableWrites($ownership['owner']);

    $actual = [];
    foreach ($found as $key => $lines) {
        $actual[] = $key.' '.count($lines);
    }
    sort($actual);

    // The line number stays out of the key and goes in the message instead. A
    // line-keyed pin fails on any edit above a write, which in a tree this busy
    // trains people to re-pin without reading.
    $describe = static function (string $entry) use ($found): string {
        $lines = $found[(string) preg_replace('/ \d+$/', '', $entry)] ?? [];

        return $entry.($lines === [] ? '' : ' (now at line '.implode(', ', $lines).')');
    };

    $added = array_map($describe, array_values(array_diff($actual, $pinned)));
    $gone = array_map($describe, array_values(array_diff($pinned, $actual)));

    expect($actual)->toBe($pinned, "Cross-module raw-table writes are pinned per file and table, so a new one is a decision.\n"
        .'Not on the list (pin it, or route the write through the owning module):'
        ."\n  ".implode("\n  ", $added === [] ? ['-'] : $added)
        ."\nPinned but not matching (the count changed, or the write went away):"
        ."\n  ".implode("\n  ", $gone === [] ? ['-'] : $gone));
});

it('pins every cross-module schema alteration to the allow-list (crossModuleSchemaAlterations)', function (): void {
    // Accepted by design, not forbidden: Modules/Core/Models/User.php carries
    // columns seven other modules added. The pin makes an eighth deliberate.
    $pinned = [
        'Anomaly -> users (owner: Core)',
        'Auth -> users (owner: Core)',
        'Budgets -> users (owner: Core)',
        'Calendar -> user_preferences (owner: Core)',
        'Categorization -> transactions (owner: Ledger)',
        'Categorization -> users (owner: Core)',
        'Counterparties -> transactions (owner: Ledger)',
        'FX -> users (owner: Core)',
        'Forecasting -> accounts (owner: Ledger)',
        'Import -> transactions (owner: Ledger)',
        'Receipts -> inbox_messages (owner: EmailScan)',
        'Recurring -> users (owner: Core)',
        'Reports -> user_preferences (owner: Core)',
        'Tax -> users (owner: Core)',
    ];

    $ownership = boundaryTableOwnership();
    $actual = [];
    foreach ($ownership['altered'] as $table => $modules) {
        foreach ($modules as $module) {
            $owner = $ownership['owner'][$table] ?? $module;
            if ($owner !== $module) {
                $actual[] = "{$module} -> {$table} (owner: {$owner})";
            }
        }
    }
    sort($actual);

    expect($actual)->toBe($pinned, "A module adding columns to a table another module created is pinned here.\n"
        .'Not on the list: '.implode(', ', array_diff($actual, $pinned) === [] ? ['-'] : array_diff($actual, $pinned))
        ."\nPinned but no longer in the migrations: "
        .implode(', ', array_diff($pinned, $actual) === [] ? ['-'] : array_diff($pinned, $actual)));
});

it('does not allow a cross-module Internal import outside the pinned production and test sets (pinnedCrossModuleInternalImports)', function (): void {
    // This replaced 34 per-module pest-arch rules that saw only files declaring a
    // namespaced class — a minority of this tree, since functional Pest files
    // declare none, module migrations are anonymous classes, and a helper declared
    // inside a Pest file lands in the global namespace. The scan here is textual.

    // A production crossing is a boundary decision, not a list edit. The only one
    // is Mobile\Internal\Sync, Sync's co-designed protocol peer, pinned by symbol
    // name in phpstan.neon as well.
    $pinnedProductionCrossings = [
        'Modules/Mobile/Internal/Sync/InitialSyncPuller.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityLoader',
        'Modules/Mobile/Internal/Sync/InitialSyncPuller.php -> Modules\\Sync\\Internal\\Transport\\Frame\\TransportFramer',
        'Modules/Mobile/Internal/Sync/InitialSyncPuller.php -> Modules\\Sync\\Internal\\Transport\\PeerCatchUpExchanger',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Config\\MergeRulesRegistry',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityDto',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Merge\\OpLogReplayer',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Signing\\DeviceKeySigner',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Transport\\Frame\\TransportFramer',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Transport\\Noise\\NoiseSession',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Transport\\PeerCatchUpExchanger',
        'Modules/Mobile/Internal/Sync/LanSyncClient.php -> Modules\\Sync\\Internal\\Transport\\SyncSession',
        'Modules/Mobile/Internal/Sync/MobileSyncTriggerService.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityDto',
        'Modules/Mobile/Internal/Sync/MobileSyncTriggerService.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityLoader',
        'Modules/Mobile/Internal/Sync/MobileSyncTriggerService.php -> Modules\\Sync\\Internal\\Transport\\Relay\\RelayClient',
        'Modules/Mobile/Internal/Sync/MobileSyncTriggerService.php -> Modules\\Sync\\Internal\\Transport\\Relay\\RelayConfig',
    ];

    // Test code reaching into a neighbour's Internal is tolerated, never free: it
    // welds the test to a private shape its owner is entitled to change. Each line
    // is one import somebody chose to write.
    $pinnedTestCrossings = [
        'Modules/Anomaly/tests/Feature/AnomalyAlertPaginationTest.php -> Modules\\DriftAlerts\\Internal\\Http\\Livewire\\DriftPage',
        'Modules/Anomaly/tests/Feature/AnomalyAlertsHomeTest.php -> Modules\\DriftAlerts\\Internal\\Http\\Livewire\\DriftPage',
        'Modules/Auth/tests/Feature/AFailingCountrySeedNeverCostsTheRecoveryCodesTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/Auth/tests/Feature/AFailingCountrySeedNeverCostsTheRecoveryCodesTest.php -> Modules\\Tax\\Internal\\Http\\Livewire\\TaxPage',
        'Modules/Auth/tests/Feature/AppLockProvisionerGdkRewrapTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Auth/tests/Feature/CrossUserIsolationTest.php -> Modules\\Import\\Internal\\Http\\Livewire\\AliasesSettingsPage',
        'Modules/Auth/tests/Feature/SignupReturnsPersistedDefaultsTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/Calendar/tests/Feature/CalendarPaletteAndSidebarTest.php -> Modules\\DevMode\\Internal\\Navigation\\NavigationRegistryImpl',
        'Modules/Calendar/tests/Feature/CalendarPaletteAndSidebarTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\AppSidebar',
        'Modules/Categorization/tests/Feature/FieldProvenanceStampingTest.php -> Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionDetail',
        'Modules/Categorization/tests/Feature/RuleApplierSyncCaptureTest.php -> Modules\\Sync\\Internal\\OpLog\\OpLogWriter',
        'Modules/Categorization/tests/Feature/RuleSchemaMigrationTest.php -> Modules\\Sync\\Internal\\Config\\MergeRulesRegistry',
        'Modules/Chains/tests/Feature/WizardChainResolutionStatusTest.php -> Modules\\Import\\Internal\\Http\\Livewire\\PreviewWizard',
        'Modules/Chains/tests/Unit/FixtureParseSmokeTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Banking\\Camt053Adapter',
        'Modules/Chains/tests/Unit/FixtureParseSmokeTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Ics\\IcsPdfAdapter',
        'Modules/Chains/tests/Unit/FixtureParseSmokeTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Paypal\\PaypalCsvAdapter',
        'Modules/Core/tests/Feature/Bootstrap/AppKeyRegenerationTest.php -> Modules\\Desktop\\Internal\\Native\\FirstLaunchBootstrap',
        'Modules/Core/tests/Feature/DatesFollowTheLanguageSwitchTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\SignupPage',
        'Modules/Core/tests/Feature/DatesFollowTheLanguageSwitchTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/Core/tests/Feature/LocaleSelectionTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\DriftAlerts\\Internal\\Jobs\\DetectDriftAlertsJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\EmailScan\\Internal\\Jobs\\BackfillInboxJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\EmailScan\\Internal\\Jobs\\DiscoveryScanJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\EmailScan\\Internal\\Jobs\\IncrementalScanJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\Forecasting\\Internal\\Jobs\\ProjectForecastJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\Receipts\\Internal\\Jobs\\ProcessFetchedInboxMessagesJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\Receipts\\Internal\\Jobs\\ScanInboxDropFolderJob',
        'Modules/Core/tests/Unit/LockStoreTest.php -> Modules\\Recurring\\Internal\\Jobs\\DetectRecurringSeriesJob',
        'Modules/Counterparties/tests/Feature/CounterpartyEncryptionTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Counterparties/tests/Feature/ResolveCounterpartyStageTest.php -> Modules\\Import\\Internal\\Pipeline\\ImportPipeline',
        'Modules/Desktop/tests/Feature/AutoUpdate/UpdateFeedSmokeTest.php -> Modules\\Core\\Internal\\AutoUpdate\\HttpPublisherManifestFetcher',
        'Modules/Desktop/tests/Feature/RelayProvisionsBeforeSpawnTest.php -> Modules\\Sync\\Internal\\Transport\\Relay\\RelayConfig',
        'Modules/Desktop/tests/Feature/RelayProvisionsBeforeSpawnTest.php -> Modules\\Sync\\Internal\\Transport\\Relay\\RelayTlsMaterial',
        'Modules/Desktop/tests/Unit/DesktopColdStartVaultTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockKeyWrap',
        'Modules/Desktop/tests/Unit/DispatchOsNotificationTest.php -> Modules\\Notifications\\Internal\\Support\\DeterministicKeyDeriver',
        'Modules/DevMode/tests/Feature/AppMenuDeveloperSubmenuTest.php -> Modules\\Desktop\\Internal\\Native\\AppMenuBuilder',
        'Modules/DriftAlerts/tests/Feature/GlobalDriftThresholdSettingTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/EmailScan/tests/Feature/EmailScanHealthTileTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\Dashboard',
        'Modules/EmailScan/tests/Feature/InvalidGrantToastTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\Dashboard',
        'Modules/FX/tests/Feature/BaseCurrencySettingTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/FX/tests/Feature/FxOnlineToggleTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/Forecasting/tests/Feature/TodayAgreesAcrossSurfacesTest.php -> Modules\\Calendar\\Internal\\Services\\DailyBalanceAggregator',
        'Modules/Import/tests/Feature/IcsPdfImportTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Ics\\IcsPdfAdapter',
        'Modules/Import/tests/Feature/IcsPdfImportTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Ics\\PdfTextExtractor',
        'Modules/Import/tests/Feature/LockedImportSaysSoWithoutNamingAClassTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Import/tests/Feature/PreviewWizardTest.php -> Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob',
        'Modules/Import/tests/Feature/PreviewWizardTest.php -> Modules\\Recurring\\Internal\\Jobs\\DetectRecurringSeriesJob',
        'Modules/Ingestion/tests/Feature/PaypalFundingLegTypingTest.php -> Modules\\Import\\Internal\\Pipeline\\Stages\\ClassifyTransactionType',
        'Modules/Ledger/tests/Feature/AmountsRemainAggregatableTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Ledger/tests/Feature/CounterpartyBlindIndexTest.php -> Modules\\Import\\Internal\\Pipeline\\Stages\\FingerprintStage',
        'Modules/Ledger/tests/Feature/DirectWriteDecryptSurvivesRotationTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkEpoch',
        'Modules/Ledger/tests/Feature/DirectWriteDecryptSurvivesRotationTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Ledger/tests/Feature/RecordTransactionsEncryptionTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Migration/tests/Contracts/MigrationImportBaselineRegistryColumnsTest.php -> Modules\\Sync\\Internal\\Config\\MergeRulesRegistry',
        'Modules/Migration/tests/Contracts/MigrationSourceMapRegistryColumnsTest.php -> Modules\\Sync\\Internal\\Config\\MergeRulesRegistry',
        'Modules/Mobile/tests/Feature/ImportWizardRecoveryDownloadTest.php -> Modules\\Desktop\\Internal\\Http\\Middleware\\EnsureDatabaseReady',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkRotationService',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkWrapRecipient',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Merge\\OpLogReplayer',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Signing\\DeviceKeySigner',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Transport\\Frame\\TransportFramer',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Transport\\Noise\\NoiseSession',
        'Modules/Mobile/tests/Feature/LanSyncClientGdkEpochReceiveTest.php -> Modules\\Sync\\Internal\\Transport\\SyncSession',
        'Modules/Mobile/tests/Feature/MobileBackgroundPullTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobileBackgroundPullTest.php -> Modules\\Sync\\Internal\\Transport\\Relay\\RelayConfig',
        'Modules/Mobile/tests/Feature/MobileBidirectionalMergeTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobileBidirectionalMergeTest.php -> Modules\\Sync\\Internal\\Merge\\OpLogReplayer',
        'Modules/Mobile/tests/Feature/MobileBidirectionalMergeTest.php -> Modules\\Sync\\Internal\\OpLog\\OpLogEntry',
        'Modules/Mobile/tests/Feature/MobileBidirectionalMergeTest.php -> Modules\\Sync\\Internal\\OpLog\\OpType',
        'Modules/Mobile/tests/Feature/MobileBidirectionalMergeTest.php -> Modules\\Sync\\Internal\\Signing\\DeviceKeySigner',
        'Modules/Mobile/tests/Feature/MobileBiometricUnlockTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobileBiometricUnlockTest.php -> Modules\\Auth\\Internal\\Lock\\BiometricDeviceStore',
        'Modules/Mobile/tests/Feature/MobileColdStartEnrollmentTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobileColdStartSettingsTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobileColdStartUnlockTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobileColdStartVaultTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobileEncryptedCopyTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Mobile/tests/Feature/MobileFirstLaunchWelcomeGateTest.php -> Modules\\Desktop\\Internal\\Http\\Middleware\\EnsureDatabaseReady',
        'Modules/Mobile/tests/Feature/MobileImportBootstrapTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobileImportBootstrapTest.php -> Modules\\Desktop\\Internal\\Http\\Middleware\\EnsureDatabaseReady',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkRotationService',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkWrapRecipient',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\Crypto\\OpLogFieldCrypto',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\Merge\\OpLogReplayer',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\OpLog\\OpLogEntry',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\OpLog\\OpLogRebuilder',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\OpLog\\OpType',
        'Modules/Mobile/tests/Feature/MobileImportInstallAndDecryptTest.php -> Modules\\Sync\\Internal\\Signing\\DeviceKeySigner',
        'Modules/Mobile/tests/Feature/MobileLockScreenForgottenPinSignpostTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobilePairingResumeOwnershipTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkEpochControlHandler',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkEpochWrapSignature',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkRotationService',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Pairing\\PairingState',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Pairing\\PairingTokenService',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Pairing\\QrPayloadBuilder',
        'Modules/Mobile/tests/Feature/MobilePairingScanCrossDeviceTest.php -> Modules\\Sync\\Internal\\Pairing\\RelayBootstrap',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Pairing\\PairingTokenService',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Pairing\\QrPayloadBuilder',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Pairing\\WordCodeEncoder',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Transport\\Discovery\\DiscoveredPeer',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Transport\\Discovery\\DiscoveryMode',
        'Modules/Mobile/tests/Feature/MobilePairingScanTest.php -> Modules\\Sync\\Internal\\Transport\\Discovery\\PeerDiscovery',
        'Modules/Mobile/tests/Feature/MobilePairingWithoutIdentityTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Mobile/tests/Feature/MobilePairingWithoutIdentityTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobilePairingWithoutIdentityTest.php -> Modules\\Sync\\Internal\\Pairing\\PairingTokenService',
        'Modules/Mobile/tests/Feature/MobilePairingWithoutIdentityTest.php -> Modules\\Sync\\Internal\\Pairing\\QrPayloadBuilder',
        'Modules/Mobile/tests/Feature/MobilePairingWithoutIdentityTest.php -> Modules\\Sync\\Internal\\Pairing\\RelayBootstrap',
        'Modules/Mobile/tests/Feature/MobileResumableInitialSyncTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkEpoch',
        'Modules/Mobile/tests/Feature/MobileResumableInitialSyncTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Mobile/tests/Feature/MobileResumableInitialSyncTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkRotationService',
        'Modules/Mobile/tests/Feature/MobileResumableInitialSyncTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkWrapRecipient',
        'Modules/Mobile/tests/Feature/MobileResumableInitialSyncTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/MobileResumableInitialSyncTest.php -> Modules\\Sync\\Internal\\Transport\\Relay\\RelayConfig',
        'Modules/Mobile/tests/Feature/PairingManualCodeArmTest.php -> Modules\\Sync\\Internal\\Identity\\DeviceIdentityService',
        'Modules/Mobile/tests/Feature/PairingManualCodeArmTest.php -> Modules\\Sync\\Internal\\Pairing\\WordCodeEncoder',
        'Modules/Mobile/tests/Feature/PairingManualCodeArmTest.php -> Modules\\Sync\\Internal\\Transport\\Discovery\\DiscoveredPeer',
        'Modules/Mobile/tests/Feature/PairingManualCodeArmTest.php -> Modules\\Sync\\Internal\\Transport\\Discovery\\DiscoveryMode',
        'Modules/Mobile/tests/Feature/PairingManualCodeArmTest.php -> Modules\\Sync\\Internal\\Transport\\Discovery\\PeerDiscovery',
        'Modules/Mobile/tests/Unit/DispatchMobileNotificationTest.php -> Modules\\Notifications\\Internal\\Support\\DeterministicKeyDeriver',
        'Modules/Mobile/tests/Unit/Identity/BiometricKeyVaultTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockKeyWrap',
        'Modules/Mobile/tests/Unit/PeerLanAddressTest.php -> Modules\\Sync\\Internal\\Transport\\Relay\\RelayConfig',
        'Modules/Notifications/tests/Feature/ABudgetNudgeNamesTheCategoryInTheReadersLanguageTest.php -> Modules\\Budgets\\Internal\\Jobs\\EmitBudgetNudgesJob',
        'Modules/Notifications/tests/Feature/BudgetNudgeTriggerTest.php -> Modules\\Budgets\\Internal\\Jobs\\EmitBudgetNudgesJob',
        'Modules/Notifications/tests/Feature/PaymentReminderTriggerTest.php -> Modules\\Recurring\\Internal\\Jobs\\EmitPaymentRemindersJob',
        'Modules/Notifications/tests/Feature/PerTriggerToggleTest.php -> Modules\\Budgets\\Internal\\Jobs\\EmitBudgetNudgesJob',
        'Modules/Notifications/tests/Feature/PerTriggerToggleTest.php -> Modules\\Desktop\\Internal\\Listeners\\DispatchOsNotification',
        'Modules/Notifications/tests/Feature/PerTriggerToggleTest.php -> Modules\\Desktop\\Internal\\Native\\WindowFocusState',
        'Modules/Notifications/tests/Feature/PerTriggerToggleTest.php -> Modules\\DriftAlerts\\Internal\\Jobs\\EmitSavingsPromptsJob',
        'Modules/Notifications/tests/Feature/PerTriggerToggleTest.php -> Modules\\Position\\Internal\\Jobs\\EmitPositionDigestJob',
        'Modules/Notifications/tests/Feature/PerTriggerToggleTest.php -> Modules\\Recurring\\Internal\\Jobs\\EmitPaymentRemindersJob',
        'Modules/Notifications/tests/Feature/PositionDigestCadenceTest.php -> Modules\\Position\\Internal\\Jobs\\EmitPositionDigestJob',
        'Modules/Notifications/tests/Feature/QuietHoursDeferTest.php -> Modules\\Desktop\\Internal\\Listeners\\DispatchOsNotification',
        'Modules/Notifications/tests/Feature/QuietHoursDeferTest.php -> Modules\\Desktop\\Internal\\Native\\WindowFocusState',
        'Modules/Notifications/tests/Feature/ReminderSelfInvalidationTest.php -> Modules\\Recurring\\Internal\\Jobs\\EmitPaymentRemindersJob',
        'Modules/Notifications/tests/Feature/SavingsPromptTriggerTest.php -> Modules\\DriftAlerts\\Internal\\Jobs\\EmitSavingsPromptsJob',
        'Modules/Onboarding/tests/Feature/ConnectPaypalStepCacheContentsTest.php -> Modules\\Import\\Internal\\Pipeline\\PreviewCache',
        'Modules/Onboarding/tests/Feature/ConnectPaypalStepReuseExistingAccountTest.php -> Modules\\Import\\Internal\\Pipeline\\PreviewCache',
        'Modules/Onboarding/tests/Feature/ConsolidatedPreviewLoadTest.php -> Modules\\Import\\Internal\\Pipeline\\PreviewCache',
        'Modules/Onboarding/tests/Feature/FirstImportStepCommitEverythingTest.php -> Modules\\Import\\Internal\\Pipeline\\PreviewCache',
        'Modules/Onboarding/tests/Feature/FirstImportStepCommitRollbackTest.php -> Modules\\Import\\Internal\\Pipeline\\PreviewCache',
        'Modules/Onboarding/tests/Feature/FirstImportStepLoadMoreTest.php -> Modules\\Import\\Internal\\Pipeline\\PreviewCache',
        'Modules/Onboarding/tests/Feature/FirstImportStepStaleIdFilterTest.php -> Modules\\Import\\Internal\\Pipeline\\PreviewCache',
        'Modules/Onboarding/tests/Feature/SignupRoutesToSetupTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\RecoveryCodesDisplay',
        'Modules/Onboarding/tests/Feature/SignupRoutesToSetupTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\SignupPage',
        'Modules/Receipts/tests/Contracts/FingerprintParityTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Ics\\IcsPdfAdapter',
        'Modules/Receipts/tests/Contracts/FingerprintParityTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Paypal\\PaypalCsvAdapter',
        'Modules/Receipts/tests/Feature/ChainHintFromReceiptTest.php -> Modules\\Import\\Internal\\Http\\Livewire\\UploadWizard',
        'Modules/Receipts/tests/Feature/ChainHintFromReceiptTest.php -> Modules\\Import\\Internal\\Pipeline\\Stages\\ParseStage',
        'Modules/Receipts/tests/Feature/EmlFileDropTest.php -> Modules\\Import\\Internal\\Http\\Livewire\\UploadWizard',
        'Modules/Receipts/tests/Feature/MboxFileDropTest.php -> Modules\\Import\\Internal\\Http\\Livewire\\UploadWizard',
        'Modules/Receipts/tests/Feature/RawPayloadDecryptChainHintListenerTest.php -> Modules\\Import\\Internal\\Http\\Livewire\\UploadWizard',
        'Modules/Search/tests/Feature/CounterpartyFilterTest.php -> Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionsList',
        'Modules/Search/tests/Feature/FtsSurvivesEncryptionTest.php -> Modules\\Sync\\Internal\\Crypto\\GdkKeyringService',
        'Modules/Search/tests/Feature/SearchEncryptionFallbackTest.php -> Modules\\Counterparties\\Internal\\Resolver\\CounterpartyResolverService',
        'Modules/Sync/tests/Feature/DuplicateReminderConvergenceTest.php -> Modules\\Notifications\\Internal\\Support\\DeterministicKeyDeriver',
        'Modules/Sync/tests/Feature/ManualEntryReachesOtherDevicesTest.php -> Modules\\CashBook\\Internal\\Actions\\RecordManualTransaction',
        'Modules/Sync/tests/Feature/PairingStateLapsesWithItsTtlTest.php -> Modules\\Mobile\\Internal\\Http\\Livewire\\MobilePairingScan',
        'Modules/Sync/tests/Feature/SystemAlertSyncCaptureTest.php -> Modules\\Auth\\Internal\\Lock\\AppLockProvisioner',
        'Modules/Sync/tests/Feature/SystemAlertSyncCaptureTest.php -> Modules\\Auth\\Internal\\Lock\\PinVerificationService',
        'Modules/Sync/tests/Feature/SystemAlertSyncCaptureTest.php -> Modules\\Auth\\Internal\\Recovery\\RecoveryCodeAuthenticator',
        'Modules/Tax/tests/Feature/LegScopedBadgeVisibilityTest.php -> Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionsList',
        'Modules/Tax/tests/Feature/ReconciledLockTaxTagTest.php -> Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionDetail',
        'Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php -> Modules\\CashBook\\Internal\\Http\\Livewire\\CashBookPage',
        'Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php -> Modules\\Counterparties\\Internal\\Http\\Livewire\\CounterpartyProfile',
        'Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php -> Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionDetail',
        'Modules/Tax/tests/Feature/TaxBadgeSurfacesTest.php -> Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionsList',
        'Modules/Tax/tests/Feature/TaxCountryPromptPointsAtTheControlTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/Tax/tests/Feature/TaxWordingComesFromTheFilingCountryTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage',
        'Modules/Transfers/tests/Feature/PairTransferCandidatesAliasBridgeTest.php -> Modules\\Import\\Internal\\Services\\KnownCounterpartyIbanResolver',
        'tests/Contracts/DriftDetectionContractTest.php -> Modules\\DriftAlerts\\Internal\\DriftEvaluator',
        'tests/Contracts/DriftDetectionContractTest.php -> Modules\\DriftAlerts\\Internal\\Jobs\\RevivedExpiredDriftSnoozesJob',
        'tests/Contracts/DriftDetectionContractTest.php -> Modules\\DriftAlerts\\Internal\\StateMachines\\DriftAlertStateMachine',
        'tests/Contracts/ForecastingProjectionContractTest.php -> Modules\\Forecasting\\Internal\\Jobs\\ProjectForecastJob',
        'tests/Contracts/RecurringDetectionContractTest.php -> Modules\\Recurring\\Internal\\Detectors\\ExpenseSeriesDetector',
        'tests/Contracts/RecurringDetectionContractTest.php -> Modules\\Recurring\\Internal\\Detectors\\IncomeSeriesDetector',
        'tests/Contracts/RecurringDetectionContractTest.php -> Modules\\Recurring\\Internal\\Jobs\\DetectRecurringSeriesJob',
        'tests/Contracts/RecurringDetectionContractTest.php -> Modules\\Recurring\\Internal\\StateMachines\\RecurringSeriesStateMachine',
        'tests/Contracts/ScenarioIsolationContractTest.php -> Modules\\Forecasting\\Internal\\Jobs\\ProjectForecastJob',
        'tests/Contracts/SecretsInLivewireSnapshotTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\AddUserPage',
        'tests/Contracts/SecretsInLivewireSnapshotTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\ChangePasswordPage',
        'tests/Contracts/SecretsInLivewireSnapshotTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\LoginPage',
        'tests/Contracts/SecretsInLivewireSnapshotTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\ManageUserPage',
        'tests/Contracts/SecretsInLivewireSnapshotTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\ResetPasswordPage',
        'tests/Contracts/SecretsInLivewireSnapshotTest.php -> Modules\\Auth\\Internal\\Http\\Livewire\\SignupPage',
        'tests/Contracts/SecretsInLivewireSnapshotTest.php -> Modules\\Mobile\\Internal\\Http\\Livewire\\MobileImportBootstrap',
        'tests/Contracts/SelectOnlyValidatorContractTest.php -> Modules\\DevMode\\Internal\\Sql\\SelectOnlyValidator',
        'tests/Feature/AnonymisedFixtureSweepTest.php -> Modules\\Ingestion\\Internal\\Adapters\\Ics\\PdfTextExtractor',
        'tests/Feature/InstallLaunchdCommandTest.php -> Modules\\Core\\Internal\\Console\\InstallCommand',
        'tests/Feature/TrustedHostGuardTest.php -> Modules\\Core\\Internal\\Http\\Middleware\\TrustedHostGuard',
        'tests/Snapshot/SidebarTest.php -> Modules\\Shell\\Internal\\Http\\Livewire\\AppSidebar',
    ];

    // BoundaryRule hooks UseItem nodes, so a fully-qualified reference written
    // inline crosses the boundary without an import and neither guard sees it.
    // Nothing does this today; the pin is empty so the first one has to argue.
    $inlineReferences = [];

    // app/ is scanned as well: an App\ class sits in no module, so BoundaryRule's
    // importer lookup returns null there and the rule never fires. Composition
    // roots that legitimately wire modules together — bootstrap/, config/,
    // routes/ — are out of scope; they are the app assembling itself.
    $scanned = ['production' => [], 'test' => []];
    foreach (['Modules', 'tests', 'app'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if (! $file->isFile() || ! str_ends_with($name, '.php') || str_ends_with($name, '.blade.php')) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            // The BoundaryRule fixtures exist in order to BE a violation, which is
            // why phpstan.neon excludes them from its own analysis too.
            if (str_starts_with($relative, 'app/PhpStan/Rules/Fixtures/')) {
                continue;
            }
            $owner = preg_match('#^Modules/([^/]+)/#', $relative, $ownerMatch) === 1 ? $ownerMatch[1] : null;
            $bucket = str_starts_with($relative, 'tests/') || str_contains($relative, '/tests/')
                ? 'test'
                : 'production';

            $contents = (string) file_get_contents($file->getPathname());
            if (! str_contains($contents, '\\Internal\\')) {
                continue;
            }

            preg_match_all(
                '/^use\s+(?:function\s+)?(Modules\\\\([A-Za-z0-9_]+)\\\\Internal\\\\[A-Za-z0-9_\\\\]+)/m',
                $contents,
                $imports,
                PREG_SET_ORDER,
            );
            foreach ($imports as $import) {
                if ($import[2] === $owner) {
                    continue;
                }
                $scanned[$bucket][] = $relative.' -> '.$import[1];
            }

            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            $stripped = preg_replace('/^use\s+[^\n]*$/m', '', $stripped) ?? $stripped;
            preg_match_all(
                '/Modules\\\\([A-Za-z0-9_]+)\\\\Internal\\\\[A-Za-z0-9_\\\\]+/',
                $stripped,
                $inline,
                PREG_SET_ORDER,
            );
            foreach ($inline as $reference) {
                if ($reference[1] !== $owner) {
                    $inlineReferences[] = $relative.' -> '.$reference[0];
                }
            }
        }
    }
    sort($scanned['production']);
    sort($scanned['test']);
    sort($inlineReferences);

    $drift = static function (array $actual, array $pinned): string {
        $added = array_values(array_diff($actual, $pinned));
        $removed = array_values(array_diff($pinned, $actual));

        return ($added === [] ? '' : "\n  NEW, not pinned:\n    ".implode("\n    ", $added))
            .($removed === [] ? '' : "\n  PINNED but no longer present, delete the line:\n    ".implode("\n    ", $removed));
    };

    expect($scanned['production'])->toBe(
        $pinnedProductionCrossings,
        'The set of PRODUCTION cross-module Internal imports has changed. Modules\\Mobile\\Internal\\Sync '
        .'is the one allow-listed crossing in the tree, because Mobile and Sync co-own a single wire '
        .'protocol; every other module reaches its neighbours through Public\\ and Models\\. If you need a '
        .'symbol from another module, add a Public contract. Do not append a line here — that widens the '
        .'boundary by one exception in a place nobody is looking.'
        .$drift($scanned['production'], $pinnedProductionCrossings),
    );

    expect($scanned['test'])->toBe(
        $pinnedTestCrossings,
        'The set of TEST cross-module Internal imports has changed. Prefer the neighbouring module\'s '
        .'Public seam; where the test genuinely needs the internal (there is no Public unlock seam, for '
        .'instance), add the line below so review sees the crossing being made rather than discovering it '
        .'later as a broken test in someone else\'s module.'
        .$drift($scanned['test'], $pinnedTestCrossings),
    );

    expect($inlineReferences)->toBe(
        [],
        'A cross-module Internal symbol is being named inline rather than imported. BoundaryRule hooks '
        .'UseItem nodes and so misses that form entirely, which is why it is banned outright here rather '
        ."than pinned. Offenders:\n  "
        .implode("\n  ", $inlineReferences),
    );
});

it('does not allow a cross-module Livewire mount outside the pinned set (pinnedCrossModuleLivewireMounts)', function (): void {
    // A Blade mount names a registered string alias, so no file imports the
    // component class and neither BoundaryRule nor a static import scan can see
    // the edge. Ownership comes from whichever provider registers the alias: the
    // prefix is not reliably the module name (dev.* is DevMode).
    $pinnedCrossModuleMounts = [
        'Modules/DevMode/Resources/views/layouts/dev-shell.blade.php -> Core (core.system-alerts-banner)',
        'Modules/DevMode/Resources/views/layouts/dev-shell.blade.php -> Search (search.palette-search-endpoint)',
        'Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php -> Auth (auth.app-lock-key-probe)',
        'Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php -> Categorization (categorization.categorization-provenance-panel)',
        'Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php -> Chains (chains.chain-drawer)',
        'Modules/Ledger/Resources/views/livewire/transactions-list.blade.php -> Categorization (categorization.inline-category-picker)',
        'Modules/Mobile/Resources/views/livewire/sync-screen.blade.php -> Auth (auth.app-lock-settings-section)',
        'Modules/Mobile/Resources/views/livewire/sync-screen.blade.php -> Core (core.auto-import-settings-section)',
        'Modules/Mobile/Resources/views/livewire/sync-screen.blade.php -> Core (core.encrypted-backup-download)',
        'Modules/Mobile/Resources/views/livewire/sync-screen.blade.php -> Core (core.encrypted-backup-restore)',
        'Modules/Mobile/Resources/views/livewire/sync-screen.blade.php -> OpenBanking (openbanking.open-banking-status-row)',
        'Modules/Mobile/Resources/views/livewire/sync-screen.blade.php -> Sync (sync.devices-and-sync-settings-section)',
        'Modules/Mobile/Resources/views/livewire/sync-screen.blade.php -> Sync (sync.sync-status-section)',
        'Modules/Onboarding/Resources/views/layouts/app-wizard.blade.php -> EmailScan (email-scan.oauth-client-wizard-modal)',
        'Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php -> DriftAlerts (drift-alerts.drift-threshold-editor)',
        'Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php -> Forecasting (forecasting.model-what-if-dropdown)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> Anomaly (anomaly.dashboard-anomaly-badge)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> Budgets (budgets.envelope-glance-card)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> DriftAlerts (drift-alerts.dashboard-drift-badge)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> DriftAlerts (drift-alerts.savings-insights-card)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> Forecasting (forecasting.forecast-highlights-tile)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> Goals (goals.summary-card)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> Recurring (recurring.fixed-payments-card)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> Reports (reports.pinned-reports-row)',
        'Modules/Shell/Resources/views/livewire/dashboard.blade.php -> Tax (tax.summary-card)',
        'Modules/Shell/Resources/views/livewire/settings-page.blade.php -> Anomaly (anomaly.settings-section)',
        'Modules/Shell/Resources/views/livewire/settings-page.blade.php -> Auth (auth.delete-account-section)',
        'Modules/Shell/Resources/views/livewire/settings-page.blade.php -> Auth (auth.recovery-codes-section)',
        'Modules/Shell/Resources/views/livewire/settings-page.blade.php -> Forecasting (forecasting.opening-balance-editor)',
        'Modules/Shell/Resources/views/livewire/settings-page.blade.php -> Ledger (ledger.account-currency-editor)',
        'Modules/Shell/Resources/views/livewire/settings-page.blade.php -> Notifications (notifications.settings-section)',
        'Modules/Shell/Resources/views/livewire/settings-page.blade.php -> Tax (tax.settings-section)',
    ];

    $providers = [];
    foreach (glob(base_path('Modules/*/Providers/*.php')) ?: [] as $providerPath) {
        preg_match('#^Modules/([^/]+)/#', str_replace(base_path().'/', '', $providerPath), $providerModule);
        $providers[] = [$providerModule[1], (string) file_get_contents($providerPath)];
    }

    $mounts = [];
    $unregistered = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        if (! str_contains($relative, '/Resources/')) {
            continue;
        }
        preg_match('#^Modules/([^/]+)/#', $relative, $ownerMatch);
        $owner = $ownerMatch[1];
        $contents = (string) file_get_contents($file->getPathname());

        // Three spellings mount a component: the alias through @livewire() — which
        // wraps onto its own line whenever it carries arguments — the alias through
        // the <livewire:...> tag, and the class itself for full-page components.
        $aliases = [];
        preg_match_all('/@livewire\s*\(\s*[\'"]([A-Za-z0-9._-]+)[\'"]/', $contents, $found);
        $aliases = array_merge($aliases, $found[1]);
        preg_match_all('/<livewire:([A-Za-z0-9._-]+)/', $contents, $found);
        $aliases = array_merge($aliases, $found[1]);

        $targets = [];
        preg_match_all(
            '/@livewire\s*\(\s*\\\\?Modules\\\\([A-Za-z0-9_]+)\\\\([A-Za-z0-9_\\\\]+)::class/',
            $contents,
            $classMounts,
            PREG_SET_ORDER,
        );
        foreach ($classMounts as $classMount) {
            $targets[] = [$classMount[1], $classMount[1].'\\'.$classMount[2]];
        }

        foreach (array_unique($aliases) as $alias) {
            $claimants = [];
            foreach ($providers as [$providerOwner, $providerSource]) {
                if (str_contains($providerSource, "'".$alias."'") || str_contains($providerSource, '"'.$alias.'"')) {
                    $claimants[$providerOwner] = true;
                }
            }
            if (count($claimants) !== 1) {
                $unregistered[] = $relative.' -> '.$alias.' (claimed by: '
                    .($claimants === [] ? 'nobody' : implode(', ', array_keys($claimants))).')';

                continue;
            }
            $targets[] = [(string) array_key_first($claimants), $alias];
        }

        foreach ($targets as [$target, $label]) {
            if ($target !== $owner) {
                $mounts[] = $relative.' -> '.$target.' ('.$label.')';
            }
        }
    }
    sort($mounts);
    sort($unregistered);

    expect($unregistered)->toBe(
        [],
        'Every Livewire alias mounted from a module view must be registered by exactly one module '
        ."provider — an alias nobody registers is a runtime failure no test covers. Offenders:\n  "
        .implode("\n  ", $unregistered),
    );

    $added = array_values(array_diff($mounts, $pinnedCrossModuleMounts));
    $removed = array_values(array_diff($pinnedCrossModuleMounts, $mounts));

    expect($mounts)->toBe(
        $pinnedCrossModuleMounts,
        'The set of cross-module Livewire mounts has changed. These are real dependencies that no import '
        .'declares, which makes them the one boundary crossing that costs nothing to add and nothing '
        .'catches: a view reaches for a neighbour\'s component and the module graph quietly grows an edge. '
        .'Adding one is allowed; adding it without a line here is not. The app shell under resources/views '
        .'is application wiring rather than a module, and is deliberately out of scope.'
        .($added === [] ? '' : "\n  NEW, not pinned:\n    ".implode("\n    ", $added))
        .($removed === [] ? '' : "\n  PINNED but no longer present, delete the line:\n    ".implode("\n    ", $removed)),
    );
});
